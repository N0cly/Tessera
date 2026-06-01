<?php

declare(strict_types=1);

namespace App\Cache;

use App\Entity\Link;
use App\Repository\LinkRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Slug → minimal redirect payload (link id + destination URL), Redis-first.
 *
 * We cache both fields together so a warm hit on /r/{slug} can dispatch the
 * scan message AND issue the 302 with zero Postgres round-trips.
 *
 * Invalidation is driven by a Doctrine listener so the cache stays in sync
 * regardless of who writes (API Platform, console, future bulk imports).
 * The safety TTL is a backstop for missed events.
 */
final class LinkCache
{
    private const KEY_PREFIX = 'link.slug.';
    private const SAFETY_TTL = 3600; // 1h backstop; explicit invalidation is primary

    public function __construct(
        #[Autowire(service: 'app.cache.links')]
        private readonly CacheInterface $cache,
        private readonly LinkRepository $links,
    ) {
    }

    /**
     * @return array{id: string, destinationUrl: string}|null
     */
    public function lookup(string $slug): ?array
    {
        return $this->cache->get($this->key($slug), function (ItemInterface $item) use ($slug): ?array {
            $item->expiresAfter(self::SAFETY_TTL);

            $link = $this->links->findOneBySlug($slug);
            if (null === $link) {
                // Don't poison the cache with negatives — unknown slugs are
                // rare and may become valid (creation race).
                $item->expiresAfter(0);

                return null;
            }

            return $this->payload($link);
        });
    }

    public function invalidate(string $slug): void
    {
        $this->cache->delete($this->key($slug));
    }

    public function warm(Link $link): void
    {
        $slug = $link->getSlug();
        if (null === $slug) {
            return;
        }
        $this->cache->delete($this->key($slug));
        $this->cache->get($this->key($slug), function (ItemInterface $item) use ($link): array {
            $item->expiresAfter(self::SAFETY_TTL);

            return $this->payload($link);
        });
    }

    /**
     * @return array{id: string, destinationUrl: string}
     */
    private function payload(Link $link): array
    {
        return [
            'id' => (string) $link->getId(),
            'destinationUrl' => $link->getDestinationUrl() ?? '',
        ];
    }

    private function key(string $slug): string
    {
        return self::KEY_PREFIX.$slug;
    }
}
