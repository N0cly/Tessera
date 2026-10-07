<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DemoShowcaseLinks;
use App\Service\FeatureFlags;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public instance configuration the frontend reads at startup to adapt the UI:
 * whether this is a demo instance, whether billing is enabled, the demo reset
 * window, and the self-host link. In demo mode it also lists the permanent
 * showcase short URLs (host → {APP_BASE_URL}/r/{slug}) the landing encodes in
 * its scannable QR. No secrets — flags and public URLs only.
 */
final class ConfigController
{
    public function __construct(
        private readonly FeatureFlags $flags,
        private readonly DemoShowcaseLinks $showcase,
    ) {
    }

    #[Route('/api/config', name: 'app_config', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $config = $this->flags->clientConfig();
        $config['showcaseLinks'] = $this->flags->isDemoMode() ? (object) $this->showcase->shortUrls() : new \stdClass();

        return new JsonResponse(
            $config,
            headers: ['Cache-Control' => 'public, max-age=30'],
        );
    }
}
