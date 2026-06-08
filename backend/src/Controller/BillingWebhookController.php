<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cache\LinkCache;
use App\Entity\BillingEvent;
use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Repository\BillingEventRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\PaddleClient;
use App\Service\PlanCatalog;
use App\Service\PricingCatalog;
use App\Service\SubscriptionManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Source of truth for billing state. The provider (Paddle) POSTs subscription
 * and payment events here. We verify the signature BEFORE touching anything,
 * map the event to a Subscription status + dates, and de-dupe by event id so
 * re-delivery is a no-op. This endpoint is public (no JWT) — its only auth is
 * the HMAC signature. See CLAUDE.md rule 14.
 */
final class BillingWebhookController
{
    public function __construct(
        private readonly PaddleClient $paddle,
        private readonly SubscriptionManager $subscriptions,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly BillingEventRepository $events,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly LinkCache $linkCache,
        private readonly PricingCatalog $pricing,
        private readonly PlanCatalog $plans,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/webhooks/billing', name: 'billing_webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        // 1) Signature first — reject anything we can't authenticate.
        if (!$this->paddle->verifyWebhookSignature($rawBody, $request->headers->get('Paddle-Signature'))) {
            $this->logger->warning('Rejected billing webhook: invalid signature.');

            return new JsonResponse(['error' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON.'], 400);
        }

        $eventId = isset($payload['event_id']) && is_string($payload['event_id']) ? $payload['event_id'] : null;
        $eventType = isset($payload['event_type']) && is_string($payload['event_type']) ? $payload['event_type'] : null;
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : null;
        if (null === $eventId || null === $eventType || null === $data) {
            return new JsonResponse(['error' => 'Malformed event.'], 400);
        }

        // 2) Idempotency — skip events we've already applied.
        if ($this->events->alreadyProcessed($eventId)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        // 3) Apply (only subscription events carry status we care about).
        if (str_starts_with($eventType, 'subscription.')) {
            $this->applySubscriptionEvent($eventType, $data);
        }

        // Catalogue changes in Paddle (prices, products, discounts) invalidate
        // the cached pricing page so the next view reflects them immediately
        // instead of waiting for the TTL (CLAUDE.md rule 16).
        if (str_starts_with($eventType, 'price.')
            || str_starts_with($eventType, 'product.')
            || str_starts_with($eventType, 'discount.')
        ) {
            $this->pricing->invalidate();
        }

        // 4) Record the event id so a re-delivery is a no-op. The unique index
        //    closes the race if two deliveries land concurrently.
        try {
            $this->em->persist(new BillingEvent($eventId, $eventType));
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Another delivery beat us to it — already applied, nothing to do.
        }

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applySubscriptionEvent(string $eventType, array $data): void
    {
        $subscription = $this->resolveSubscription($data);
        if (null === $subscription) {
            $this->logger->warning('Billing webhook: could not map event to a subscription.', [
                'event_type' => $eventType,
            ]);

            return;
        }

        $status = 'subscription.canceled' === $eventType
            ? SubscriptionStatus::Canceled
            : $this->mapStatus(is_string($data['status'] ?? null) ? $data['status'] : null);

        // Which plan was purchased is derived from the price id on the
        // subscription's items, mapped through PlanCatalog (the single source).
        // If the price is unrecognized we DO NOT elevate — we leave the existing
        // plan untouched (passing null) and log loudly. Defaulting an unknown
        // price to the highest-entitlement plan would silently hand out Pro for
        // free whenever the Paddle catalogue and PADDLE_*_PRICE_ID drift; failing
        // toward least entitlement is the safe direction (mirrors how a lapsed
        // sub falls back to the trial limit in PlanCatalog::codeLimitFor()).
        $plan = $this->planFromData($data);
        if (null === $plan) {
            $this->logger->warning(
                'Billing webhook: subscription price id maps to no configured plan; leaving the plan unchanged. '
                .'Check that PADDLE_STARTER_PRICE_ID / PADDLE_PRO_PRICE_ID match your Paddle catalogue.',
                ['event_type' => $eventType],
            );
        }

        $this->subscriptions->applyProviderState(
            $subscription,
            $status,
            $plan, // null = keep the current plan; never auto-elevate on an unknown price
            is_string($data['id'] ?? null) ? $data['id'] : null,
            is_string($data['customer_id'] ?? null) ? $data['customer_id'] : null,
            $this->parsePeriodEnd($data),
        );

        // The owner's status/dates changed → the cached grace boundary for all
        // their codes is now stale. Bust it so the next scan recomputes the
        // target (CLAUDE.md rule 15).
        $this->linkCache->invalidateForOwner($subscription->getUser());
    }

    /**
     * Find the subscription this event belongs to. Prefer the user reference we
     * planted in custom_data at checkout; fall back to provider ids for events
     * that omit it (e.g. provider-initiated changes).
     *
     * @param array<string, mixed> $data
     */
    private function resolveSubscription(array $data): ?Subscription
    {
        $custom = $data['custom_data'] ?? null;
        $userId = is_array($custom) && is_string($custom['user_id'] ?? null) ? $custom['user_id'] : null;
        if (null !== $userId && Uuid::isValid($userId)) {
            $user = $this->users->find(Uuid::fromString($userId));
            if (null !== $user) {
                return $this->subscriptions->getOrCreate($user);
            }
        }

        if (is_string($data['id'] ?? null)) {
            $found = $this->subscriptionRepo->findOneByProviderSubscriptionId($data['id']);
            if (null !== $found) {
                return $found;
            }
        }

        if (is_string($data['customer_id'] ?? null)) {
            return $this->subscriptionRepo->findOneByProviderCustomerId($data['customer_id']);
        }

        return null;
    }

    /**
     * Resolve the purchased plan from the subscription's line items. Paddle
     * sends each item's price under `items[].price.id`; we map the first one we
     * recognize through PlanCatalog. Returns null if no item maps to a plan.
     *
     * @param array<string, mixed> $data
     */
    private function planFromData(array $data): ?string
    {
        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $priceId = $item['price']['id'] ?? ($item['price_id'] ?? null);
            if (is_string($priceId) && '' !== $priceId) {
                $plan = $this->plans->planForPriceId($priceId);
                if (null !== $plan) {
                    return $plan;
                }
            }
        }

        return null;
    }

    private function mapStatus(?string $providerStatus): SubscriptionStatus
    {
        return match ($providerStatus) {
            'active' => SubscriptionStatus::Active,
            'trialing' => SubscriptionStatus::Trialing,
            'past_due' => SubscriptionStatus::PastDue,
            'paused' => SubscriptionStatus::PastDue,
            'canceled' => SubscriptionStatus::Canceled,
            'expired' => SubscriptionStatus::Expired,
            default => SubscriptionStatus::Active,
        };
    }

    /**
     * Pull current_billing_period.ends_at when present.
     *
     * @param array<string, mixed> $data
     */
    private function parsePeriodEnd(array $data): ?\DateTimeImmutable
    {
        $period = $data['current_billing_period'] ?? null;
        $endsAt = is_array($period) && is_string($period['ends_at'] ?? null) ? $period['ends_at'] : null;
        if (null === $endsAt) {
            return null;
        }

        try {
            return new \DateTimeImmutable($endsAt);
        } catch (\Exception) {
            return null;
        }
    }
}
