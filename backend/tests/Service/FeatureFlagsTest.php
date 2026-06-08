<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FeatureFlags;
use PHPUnit\Framework\TestCase;

final class FeatureFlagsTest extends TestCase
{
    public function testReadsTheTwoIndependentFlags(): void
    {
        $on = new FeatureFlags(true, true, 2, 10, 'https://example.test/repo');
        self::assertTrue($on->isDemoMode());
        self::assertTrue($on->isBillingEnabled());

        $off = new FeatureFlags(false, false, 1, 5, null);
        self::assertFalse($off->isDemoMode());
        self::assertFalse($off->isBillingEnabled());
    }

    public function testSensibleFallbacks(): void
    {
        $f = new FeatureFlags(false, false, 0, 0, null);
        self::assertSame(1, $f->demoResetHours(), '<= 0 reset window falls back to 1h');
        self::assertSame(5, $f->demoLinkQuota(), '<= 0 quota falls back to 5');
        self::assertStringContainsString('github.com', $f->githubUrl(), 'empty url falls back to the repo');
    }

    public function testClientConfigShape(): void
    {
        $f = new FeatureFlags(true, false, 1, 5, 'https://gh.test/x');

        self::assertSame([
            'demoMode' => true,
            'billingEnabled' => false,
            'demoResetHours' => 1,
            'githubUrl' => 'https://gh.test/x',
        ], $f->clientConfig());
    }
}
