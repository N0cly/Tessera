<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DemoSession;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class DemoSessionTest extends TestCase
{
    public function testHoldsTokenAndUser(): void
    {
        $user = new User();
        $session = new DemoSession($user, 'opaque-token');

        self::assertSame('opaque-token', $session->getToken());
        self::assertSame($user, $session->getUser());
        self::assertEquals($session->getCreatedAt(), $session->getLastActivityAt());
    }

    public function testStalenessFollowsLastActivity(): void
    {
        $session = new DemoSession(new User(), 'tok');

        self::assertFalse($session->isStale(1), 'a fresh session is not stale');

        $session->touch(new \DateTimeImmutable('-2 hours'));
        self::assertTrue($session->isStale(1), 'idle 2h is stale for a 1h window');
        self::assertFalse($session->isStale(3), 'still within a 3h window');

        $session->touch();
        self::assertFalse($session->isStale(1), 'touching resets the inactivity clock');
    }
}
