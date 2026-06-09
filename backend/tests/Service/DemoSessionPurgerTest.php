<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Cache\LinkCache;
use App\Repository\DemoSessionRepository;
use App\Repository\LinkRepository;
use App\Service\DemoSessionPurger;
use App\Service\FeatureFlags;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The scheduled purge must bust the Redis redirect cache for the reaped slugs —
 * the bulk DELETE cascades at the DB level and won't fire the ORM cache listener,
 * so without this a purged demo code keeps serving until the safety TTL.
 */
final class DemoSessionPurgerTest extends TestCase
{
    public function testDisabledWhenDemoModeOff(): void
    {
        $sessions = $this->createMock(DemoSessionRepository::class);
        $sessions->expects(self::never())->method('purgeStale');

        $purger = new DemoSessionPurger(
            new FeatureFlags(false, false, 1, 5, null),
            $sessions,
            $this->linkCache($this->createStub(CacheInterface::class)),
            new NullLogger(),
        );

        self::assertSame(0, $purger->purge());
    }

    public function testBustsTheRedirectCacheForEveryPurgedSlug(): void
    {
        $sessions = $this->createStub(DemoSessionRepository::class);
        $sessions->method('findSlugsForStaleSessions')->willReturn(['alpha01', 'beta02']);
        $sessions->method('purgeStale')->willReturn(2);

        $cache = $this->createStub(CacheInterface::class);
        $deleted = [];
        $cache->method('delete')->willReturnCallback(static function (string $key) use (&$deleted): bool {
            $deleted[] = $key;

            return true;
        });

        $purger = new DemoSessionPurger(
            new FeatureFlags(true, false, 1, 5, null),
            $sessions,
            $this->linkCache($cache),
            new NullLogger(),
        );

        self::assertSame(2, $purger->purge());
        self::assertSame(['link.slug.alpha01', 'link.slug.beta02'], $deleted);
    }

    private function linkCache(CacheInterface $cache): LinkCache
    {
        // LinkCache is final; drive it through a mock cache so invalidate($slug)
        // is observable as a delete on the prefixed key.
        return new LinkCache(
            $cache,
            $this->createStub(LinkRepository::class),
        );
    }
}
