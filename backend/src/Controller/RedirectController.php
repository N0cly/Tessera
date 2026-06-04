<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cache\LinkCache;
use App\Http\InactivePageRenderer;
use App\Message\ScanRecorded;
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
 *
 * Fallback (CLAUDE.md rule 15): the target is chosen from the cached payload
 * with a plain `now vs graceEndsAt` compare — no subscription join per scan.
 * Once the owner's subscription has lapsed beyond grace, the code redirects to
 * its fallback URL, or shows the neutral inactive page if none is set.
 */
final class RedirectController
{
    public function __construct(
        private readonly LinkCache $cache,
        private readonly MessageBusInterface $bus,
        private readonly InactivePageRenderer $inactivePage,
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
        $this->bus->dispatch(new ScanRecorded(
            linkId: $hit['id'],
            scannedAtIso: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            userAgent: $request->headers->get('User-Agent'),
            ip: $request->getClientIp(),
            referrer: $request->headers->get('Referer'),
        ));

        // Self-resolving grace boundary: switch to fallback only once we're at
        // or past graceEndsAt. Null grace = active/trial → always destination.
        $graceEndsAt = $hit['graceEndsAt'];
        $lapsed = null !== $graceEndsAt && time() >= $graceEndsAt;

        if (!$lapsed) {
            return new RedirectResponse($hit['destinationUrl'], Response::HTTP_FOUND);
        }

        if (null !== $hit['fallbackUrl'] && '' !== $hit['fallbackUrl']) {
            return new RedirectResponse($hit['fallbackUrl'], Response::HTTP_FOUND);
        }

        return $this->inactivePage->render();
    }
}
