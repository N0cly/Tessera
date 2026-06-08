<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\FeatureFlags;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public instance configuration the frontend reads at startup to adapt the UI:
 * whether this is a demo instance, whether billing is enabled, the demo reset
 * window, and the self-host link. No secrets — flags only.
 */
final class ConfigController
{
    public function __construct(
        private readonly FeatureFlags $flags,
    ) {
    }

    #[Route('/api/config', name: 'app_config', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            $this->flags->clientConfig(),
            headers: ['Cache-Control' => 'public, max-age=30'],
        );
    }
}
