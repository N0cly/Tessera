<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\SubscriptionRepository;
use App\Service\AdminBillingMetrics;
use App\Service\PaddleClient;
use App\Service\PlanCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class AdminBillingMetricsTest extends TestCase
{
    private function metrics(): AdminBillingMetrics
    {
        // computeFromSubscriptions() is pure and only uses PlanCatalog; the
        // other collaborators are present just to satisfy the constructor.
        return new AdminBillingMetrics(
            new ArrayAdapter(),
            new PaddleClient(new MockHttpClient(), 'https://x', '', ''),
            new PlanCatalog(25, 100, 1000, 'pri_starter', 'pri_pro', null),
            $this->createStub(SubscriptionRepository::class),
            300,
        );
    }

    private function sub(string $status, ?string $priceId = null, string $amount = '0', string $interval = 'month', int $freq = 1, int $qty = 1, string $currency = 'EUR'): array
    {
        return [
            'status' => $status,
            'items' => [[
                'quantity' => $qty,
                'price' => [
                    'id' => $priceId,
                    'unit_price' => ['amount' => $amount, 'currency_code' => $currency],
                    'billing_cycle' => ['interval' => $interval, 'frequency' => $freq],
                ],
            ]],
        ];
    }

    public function testComputesMrrCountsAndPlanBreakdown(): void
    {
        $result = $this->metrics()->computeFromSubscriptions([
            $this->sub('active', 'pri_starter', '300'),               // 3.00/mo
            $this->sub('active', 'pri_pro', '1500'),                  // 15.00/mo
            $this->sub('active', 'pri_pro', '12000', 'year'),         // 120.00/yr → 10.00/mo
            $this->sub('trialing', 'pri_starter', '300'),
            $this->sub('past_due', 'pri_pro', '1500'),
            $this->sub('canceled', 'pri_pro', '1500'),
        ]);

        self::assertSame(3, $result['activeSubscriptions']);
        self::assertSame(1, $result['trialing']);
        self::assertSame(1, $result['pastDue']);
        self::assertSame(1, $result['canceled']);
        self::assertSame(2800, $result['mrr'], '300 + 1500 + 1000 = 2800 minor units/month');
        self::assertSame('EUR', $result['currency']);
        self::assertSame(['starter' => 1, 'pro' => 2, 'other' => 0], $result['byPlan']);
    }

    public function testYearlyAndWeeklyNormalizeToMonthly(): void
    {
        $yearly = $this->metrics()->computeFromSubscriptions([
            $this->sub('active', 'pri_pro', '12000', 'year'),
        ]);
        self::assertSame(1000, $yearly['mrr'], '120.00/yr → 10.00/mo');

        // 5.00 charged every 2 months → 2.50/mo.
        $everyTwoMonths = $this->metrics()->computeFromSubscriptions([
            $this->sub('active', 'pri_starter', '500', 'month', 2),
        ]);
        self::assertSame(250, $everyTwoMonths['mrr']);
    }

    public function testUnknownPriceCountsAsOtherPlan(): void
    {
        $result = $this->metrics()->computeFromSubscriptions([
            $this->sub('active', 'pri_unrecognized', '999'),
        ]);

        self::assertSame(['starter' => 0, 'pro' => 0, 'other' => 1], $result['byPlan']);
        self::assertSame(999, $result['mrr']);
    }

    public function testQuantityMultipliesAmount(): void
    {
        $result = $this->metrics()->computeFromSubscriptions([
            $this->sub('active', 'pri_pro', '1500', 'month', 1, 3),
        ]);

        self::assertSame(4500, $result['mrr'], '3 seats × 15.00');
    }

    public function testEmptyAndNonActiveProduceZeroMrr(): void
    {
        $result = $this->metrics()->computeFromSubscriptions([
            $this->sub('canceled', 'pri_pro', '1500'),
            $this->sub('trialing', 'pri_starter', '300'),
        ]);

        self::assertSame(0, $result['mrr']);
        self::assertNull($result['currency'], 'no active sub → no currency');
        self::assertFalse($result['mixedCurrency']);
        self::assertSame(0, $result['activeSubscriptions']);
    }

    public function testSingleCurrencyReportsMrrAndCurrency(): void
    {
        $result = $this->metrics()->computeFromSubscriptions([
            $this->sub('active', 'pri_starter', '300'),
            $this->sub('active', 'pri_pro', '1500'),
        ]);

        self::assertFalse($result['mixedCurrency']);
        self::assertSame('EUR', $result['currency']);
        self::assertSame(1800, $result['mrr']);
    }

    public function testMixedCurrencyFailsSafeInsteadOfSummingNonsense(): void
    {
        $result = $this->metrics()->computeFromSubscriptions([
            $this->sub('active', 'pri_starter', '300', 'month', 1, 1, 'EUR'),
            $this->sub('active', 'pri_pro', '1500', 'month', 1, 1, 'USD'),
        ]);

        self::assertTrue($result['mixedCurrency'], 'mixed currencies detected');
        self::assertNull($result['mrr'], 'never sum across currencies into one wrong number');
        self::assertNull($result['currency']);
        self::assertSame(2, $result['activeSubscriptions'], 'counts are still correct');
    }
}
