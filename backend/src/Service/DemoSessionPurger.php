<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\LinkCache;
use App\Repository\DemoSessionRepository;
use Psr\Log\LoggerInterface;

/**
 * Reaps demo workspaces idle past the reset window (DEMO_SESSION_TTL_HOURS).
 * Runs from the scheduled cron job; the per-request subscriber also reaps
 * lazily on access. No-op unless DEMO_MODE is on.
 */
final class DemoSessionPurger
{
    public function __construct(
        private readonly FeatureFlags $flags,
        private readonly DemoSessionRepository $sessions,
        private readonly LinkCache $linkCache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return int number of stale workspaces purged */
    public function purge(?\DateTimeImmutable $now = null): int
    {
        if (!$this->flags->isDemoMode()) {
            return 0;
        }

        $cutoff = ($now ?? new \DateTimeImmutable())
            ->modify(sprintf('-%d hours', $this->flags->demoResetHours()));

        // Collect the slugs BEFORE the bulk DELETE (which cascades at the DB
        // level and won't fire the ORM cache-invalidation listener), then bust
        // the redirect cache so purged codes don't keep serving from Redis.
        $slugs = $this->sessions->findSlugsForStaleSessions($cutoff);
        $count = $this->sessions->purgeStale($cutoff);
        foreach ($slugs as $slug) {
            $this->linkCache->invalidate($slug);
        }

        if ($count > 0) {
            $this->logger->info('Purged stale demo workspaces.', ['count' => $count]);
        }

        return $count;
    }
}
