<?php

declare(strict_types=1);

namespace App\Cache;

use App\Entity\Link;
use App\Entity\User;
use App\Repository\LinkRepository;
use App\Repository\SubscriptionRepository;
use App\Service\GraceCalculator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Slug → minimal redirect payload, Redis-first.
 *
 * We cache everything /r/{slug} needs to choose its target AND dispatch the
 * scan with zero Postgres round-trips on a warm hit: the link id, the
 * destination, the owner's fallback, and the precomputed grace boundary. The
 * fallback decision is then a pure `now vs graceEndsAt` compare at read time —
 * no per-scan subscription join (CLAUDE.md rule 15).
 *
 * Invalidation is driven by:
 *  - a Doctrine listener on Link writes (destination/fallback edits), and
 *  - the billing webhook, when the owner's subscription status changes.
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
        private readonly SubscriptionRepository $subscriptions,
        private readonly GraceCalculator $grace,
    ) {
    }

    /**
     * @return array{id: string, destinationUrl: string, fallbackUrl: ?string, graceEndsAt: ?int, lapsed: bool}|null
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
     * Bust the redirect cache for every code owned by a user. Called by the
     * billing webhook when the owner's subscription status changes, so the next
     * scan rebuilds the payload with the new grace boundary. We delete rather
     * than warm — a status change is rare and the rebuild is a single query.
     */
    public function invalidateForOwner(User $owner): void
    {
        foreach ($this->links->findSlugsByOwner($owner) as $slug) {
            $this->cache->delete($this->key($slug));
        }
    }

    /**
     * @return array{id: string, destinationUrl: string, fallbackUrl: ?string, graceEndsAt: ?int, lapsed: bool}
     */
    private function payload(Link $link): array
    {
        // Resolve the owner's subscription ONCE here (cache-miss only). The hot
        // path never sees this query — it reads the derived fields below.
        $owner = $link->getOwner();
        $subscription = null !== $owner ? $this->subscriptions->findOneByUser($owner) : null;
        $graceEndsAt = $this->grace->graceEndsAt($subscription);

        return [
            'id' => (string) $link->getId(),
            'destinationUrl' => $link->getDestinationUrl() ?? '',
            'fallbackUrl' => $link->getFallbackUrl(),
            'graceEndsAt' => $graceEndsAt?->getTimestamp(),
            'lapsed' => $this->grace->isLapsing($subscription),
        ];
    }

    private function key(string $slug): string
    {
        return self::KEY_PREFIX.$slug;
    }
}
