<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\LinkRepository;
use App\Service\GraceCalculator;
use App\Service\PaddleClient;
use App\Service\PlanCatalog;
use App\Service\SubscriptionManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use App\Service\FeatureFlags;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Owner-scoped billing surface: read the real subscription, start a hosted
 * checkout, and open the customer portal. Access is NEVER granted here from a
 * checkout return — the webhook is the source of truth (CLAUDE.md rule 14).
 */
final class BillingController
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly PlanCatalog $plans,
        private readonly LinkRepository $links,
        private readonly PaddleClient $paddle,
        private readonly GraceCalculator $grace,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly FeatureFlags $flags,
    ) {
    }

    #[Route('/api/billing/subscription', name: 'billing_subscription', methods: ['GET'])]
    public function subscription(): JsonResponse
    {
        $user = $this->currentUser();
        $sub = $this->subscriptions->getOrCreate($user);
        $codesUsed = $this->links->countForOwner($user);
        $codeLimit = $this->plans->codeLimitFor($sub);

        return new JsonResponse([
            'plan' => $sub->getPlan(),
            'planName' => $this->plans->displayName($sub->getPlan()),
            'status' => $sub->getStatus()->value,
            'trialEndsAt' => $sub->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
            'trialDaysLeft' => $sub->trialDaysLeft(),
            'currentPeriodEndsAt' => $sub->getCurrentPeriodEndsAt()?->format(\DateTimeInterface::ATOM),
            'codesUsed' => $codesUsed,
            'codeLimit' => $codeLimit,
            'graceDays' => $this->grace->days(),
            'checkoutAvailable' => $this->flags->isBillingEnabled() && $this->paddle->isConfigured() && $this->plans->hasConfiguredPlan(),
            'portalAvailable' => $this->flags->isBillingEnabled() && $this->paddle->isConfigured() && null !== $sub->getProviderCustomerId(),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/billing/checkout', name: 'billing_checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $this->assertBillingEnabled();
        $user = $this->currentUser();
        // Ensure a subscription row exists so the webhook has something to map to.
        $this->subscriptions->getOrCreate($user);

        // The plan to subscribe to comes from the request (pricing page passes
        // "starter"/"pro"). Fall back to the first configured paid plan so the
        // dashboard's plain "subscribe" button keeps working. The price id is
        // resolved through PlanCatalog — the single source — never hardcoded.
        $payload = json_decode($request->getContent() ?: '{}', true);
        $requested = is_array($payload) && is_string($payload['plan'] ?? null) ? $payload['plan'] : null;
        $plan = (null !== $requested && $this->plans->isPaidPlan($requested))
            ? $requested
            : $this->firstConfiguredPaidPlan();

        $priceId = null !== $plan ? $this->plans->priceIdForPlan($plan) : null;
        if (null === $priceId) {
            throw new ServiceUnavailableHttpException(message: $this->translator->trans('billing.not_configured'));
        }

        try {
            // Pass the user's locale so the Paddle checkout renders in it.
            $url = $this->paddle->createCheckoutUrl($user, $priceId, $user->getLocale());
        } catch (\RuntimeException $e) {
            throw new ServiceUnavailableHttpException(message: $this->translator->trans('billing.not_configured'));
        }

        return new JsonResponse(['checkoutUrl' => $url]);
    }

    #[Route('/api/billing/portal', name: 'billing_portal', methods: ['POST'])]
    public function portal(): JsonResponse
    {
        $this->assertBillingEnabled();
        $user = $this->currentUser();
        $sub = $this->subscriptions->getOrCreate($user);

        $customerId = $sub->getProviderCustomerId();
        if (null === $customerId) {
            throw new ConflictHttpException($this->translator->trans('billing.no_subscription'));
        }

        $url = $this->paddle->createPortalUrl($customerId);
        if (null === $url) {
            throw new ServiceUnavailableHttpException(message: $this->translator->trans('billing.portal_failed'));
        }

        return new JsonResponse(['portalUrl' => $url]);
    }

    /**
     * The first paid plan that actually has a Paddle price configured, or null
     * if none do (billing disabled). Used as the default when no plan is named.
     */
    private function firstConfiguredPaidPlan(): ?string
    {
        foreach ($this->plans->paidPlans() as $plan) {
            if (null !== $this->plans->priceIdForPlan($plan)) {
                return $plan;
            }
        }

        return null;
    }

    /** Billing is a separately-flagged feature (off in demo / by default). */
    private function assertBillingEnabled(): void
    {
        if (!$this->flags->isBillingEnabled()) {
            throw new ServiceUnavailableHttpException(message: $this->translator->trans('billing.not_configured'));
        }
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }
}
