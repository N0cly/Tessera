<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PricingCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public pricing catalogue: the paid plans with their live Paddle prices +
 * active discounts, paired with the per-plan code limits from PlanCatalog.
 *
 * A plain lightweight controller (NOT an API Platform resource) and public (no
 * JWT). Prices are never hardcoded — they come from Paddle via PricingCatalog,
 * which caches in Redis and fails safe (an unreadable plan comes back
 * `available: false` rather than with a wrong number). See CLAUDE.md rule 16
 * and tessera-pricing-page.md.
 */
final class PricingController
{
    public function __construct(
        private readonly PricingCatalog $pricing,
    ) {
    }

    #[Route('/api/pricing', name: 'pricing', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            ['plans' => $this->pricing->plans()],
            headers: ['Cache-Control' => 'public, max-age=60'],
        );
    }
}
