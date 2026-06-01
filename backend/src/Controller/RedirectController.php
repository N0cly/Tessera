<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cache\LinkCache;
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
 */
final class RedirectController
{
    public function __construct(
        private readonly LinkCache $cache,
        private readonly MessageBusInterface $bus,
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

        $this->bus->dispatch(new ScanRecorded(
            linkId: $hit['id'],
            scannedAtIso: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            userAgent: $request->headers->get('User-Agent'),
            ip: $request->getClientIp(),
            referrer: $request->headers->get('Referer'),
        ));

        return new RedirectResponse($hit['destinationUrl'], Response::HTTP_FOUND);
    }
}
