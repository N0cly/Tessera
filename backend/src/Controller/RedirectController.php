<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cache\LinkCache;
use App\Http\DemoInterstitialRenderer;
use App\Message\ScanRecorded;
use App\Service\DemoRedirectAllowlist;
use App\Service\FeatureFlags;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public redirect hot path. MUST stay outside API Platform — see CLAUDE.md.
 *
 * Hot path on a warm cache hit: ONE Redis GET, ONE Redis LPUSH (Messenger),
 * then 302 — zero Postgres round-trips. Always 302, never 301: destinations
 * are editable and a 301 would freeze old targets in browser caches forever.
 */
final class RedirectController
{
    public function __construct(
        private readonly LinkCache $cache,
        private readonly MessageBusInterface $bus,
        private readonly FeatureFlags $flags,
        private readonly DemoInterstitialRenderer $demoInterstitial,
        private readonly DemoRedirectAllowlist $demoAllowlist,
    ) {
    }

    #[Route(
        '/r/{slug}',
        name: 'link_redirect',
        requirements: ['slug' => '[A-Za-z0-9]{1,32}'],
        methods: ['GET'],
    )]
    public function __invoke(string $slug, Request $request): Response
    {
        $hit = $this->cache->lookup($slug);
        if (null === $hit) {
            throw new NotFoundHttpException(sprintf('No link for slug "%s".', $slug));
        }

        // The QR was scanned regardless of where it points — always record it.
        // In demo mode we deliberately drop the scanner IP: the public demo takes
        // high-volume anonymous traffic and the (simulated) scan needs no real
        // geo, so we never let a real visitor IP enter the message pipeline.
        $this->bus->dispatch(new ScanRecorded(
            linkId: $hit['id'],
            scannedAtIso: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            userAgent: $request->headers->get('User-Agent'),
            ip: $this->flags->isDemoMode() ? null : $request->getClientIp(),
            referrer: $request->headers->get('Referer'),
        ));

        // DEMO MODE — CRITICAL (tessera-demo-mode.md, tessera-demo-real-redirects.md):
        // /r/{slug} is global and public, so session isolation does NOT cover it.
        // A real 302 happens ONLY for a seeded link whose CURRENT destination host
        // is on the operator allowlist — checked here, server-side, at redirect
        // time. Visitor-created links, and seeded links repointed off-list, get the
        // safe interstitial. The scan above is recorded either way.
        if ($this->flags->isDemoMode()) {
            $realRedirect = ($hit['demoSeeded'] ?? false)
                && $this->demoAllowlist->allows($hit['destinationUrl']);
            if (!$realRedirect) {
                return $this->demoInterstitial->render($hit['destinationUrl'], $request->getLocale());
            }
        }

        return new RedirectResponse($hit['destinationUrl'], Response::HTTP_FOUND);
    }
}
