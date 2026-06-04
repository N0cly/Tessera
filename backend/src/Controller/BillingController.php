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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

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
            'checkoutAvailable' => $this->paddle->isConfigured(),
            'portalAvailable' => $this->paddle->isConfigured() && null !== $sub->getProviderCustomerId(),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/billing/checkout', name: 'billing_checkout', methods: ['POST'])]
    public function checkout(): JsonResponse
    {
        $user = $this->currentUser();
        // Ensure a subscription row exists so the webhook has something to map to.
        $this->subscriptions->getOrCreate($user);

        try {
            $url = $this->paddle->createCheckoutUrl($user);
        } catch (\RuntimeException $e) {
            throw new ServiceUnavailableHttpException(message: $e->getMessage());
        }

        return new JsonResponse(['checkoutUrl' => $url]);
    }

    #[Route('/api/billing/portal', name: 'billing_portal', methods: ['POST'])]
    public function portal(): JsonResponse
    {
        $user = $this->currentUser();
        $sub = $this->subscriptions->getOrCreate($user);

        $customerId = $sub->getProviderCustomerId();
        if (null === $customerId) {
            throw new ConflictHttpException('No active subscription to manage yet.');
        }

        $url = $this->paddle->createPortalUrl($customerId);
        if (null === $url) {
            throw new ServiceUnavailableHttpException(message: 'Could not open the customer portal.');
        }

        return new JsonResponse(['portalUrl' => $url]);
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
