<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PaddleClient;
use App\Service\PlanCatalog;
use App\Service\PricingCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PricingCatalogTest extends TestCase
{
    private const STARTER_PRICE = 'pri_starter';
    private const PRO_PRICE = 'pri_pro';
    private const STARTER_PRODUCT = 'pro_starter';
    private const PRO_PRODUCT = 'pro_pro';

    /**
     * @param list<array<string, mixed>> $discounts
     */
    private function build(
        array $discounts = [],
        bool $proFails = false,
        bool $discountsFail = false,
        ?int &$priceCalls = null,
    ): PricingCatalog {
        $priceCalls = 0;
        $client = new MockHttpClient(function (string $method, string $url) use ($discounts, $proFails, $discountsFail, &$priceCalls): MockResponse {
            if (str_contains($url, '/prices/'.self::STARTER_PRICE)) {
                ++$priceCalls;

                return new MockResponse((string) json_encode([
                    'data' => [
                        'unit_price' => ['amount' => '300', 'currency_code' => 'EUR'],
                        'billing_cycle' => ['interval' => 'month', 'frequency' => 1],
                        'product_id' => self::STARTER_PRODUCT,
                    ],
                ]));
            }
            if (str_contains($url, '/prices/'.self::PRO_PRICE)) {
                ++$priceCalls;
                if ($proFails) {
                    return new MockResponse('{"error":"boom"}', ['http_code' => 500]);
                }

                return new MockResponse((string) json_encode([
                    'data' => [
                        'unit_price' => ['amount' => '1500', 'currency_code' => 'EUR'],
                        'billing_cycle' => ['interval' => 'month', 'frequency' => 1],
                        'product_id' => self::PRO_PRODUCT,
                    ],
                ]));
            }
            if (str_contains($url, '/discounts')) {
                if ($discountsFail) {
                    return new MockResponse('{"error":"boom"}', ['http_code' => 500]);
                }

                return new MockResponse((string) json_encode(['data' => $discounts]));
            }

            return new MockResponse('{}', ['http_code' => 404]);
        });

        $paddle = new PaddleClient($client, 'https://sandbox-api.paddle.com', 'test_key', 'test_secret');
        $plans = new PlanCatalog(25, 100, 1000, self::STARTER_PRICE, self::PRO_PRICE, null);

        return new PricingCatalog(new ArrayAdapter(), $paddle, $plans, 600);
    }

    /**
     * @param list<array<string, mixed>> $plans
     */
    private function plan(array $plans, string $key): array
    {
        foreach ($plans as $p) {
            if ($p['plan'] === $key) {
                return $p;
            }
        }
        self::fail("plan {$key} not present");
    }

    private function future(): string
    {
        return (new \DateTimeImmutable('+30 days'))->format(\DateTimeInterface::ATOM);
    }

    public function testPricesAreReadLiveFromPaddleWithNoPromo(): void
    {
        $plans = $this->build()->plans();

        $starter = $this->plan($plans, 'starter');
        self::assertTrue($starter['available']);
        self::assertSame(300, $starter['amount']);
        self::assertSame('EUR', $starter['currency']);
        self::assertSame('month', $starter['interval']);
        self::assertSame(100, $starter['codeLimit']);
        self::assertNull($starter['promo']);

        $pro = $this->plan($plans, 'pro');
        self::assertSame(1500, $pro['amount']);
        self::assertSame(1000, $pro['codeLimit']);
    }

    public function testPercentageDiscountProducesPromo(): void
    {
        $plans = $this->build([[
            'id' => 'dsc_1',
            'status' => 'active',
            'type' => 'percentage',
            'amount' => '20',
            'description' => 'Launch offer',
            'expires_at' => $this->future(),
            'restrict_to' => [self::STARTER_PRICE],
        ]])->plans();

        $promo = $this->plan($plans, 'starter')['promo'];
        self::assertNotNull($promo);
        self::assertSame('percentage', $promo['type']);
        self::assertSame(20.0, $promo['amount']);
        self::assertSame(240, $promo['finalAmount'], '300 − 20% = 240');
        self::assertSame('Launch offer', $promo['label']);

        self::assertNull($this->plan($plans, 'pro')['promo'], 'discount restricted to starter only');
    }

    public function testFlatDiscountInSameCurrency(): void
    {
        $plans = $this->build([[
            'id' => 'dsc_2',
            'status' => 'active',
            'type' => 'flat',
            'amount' => '100',
            'currency_code' => 'EUR',
            'expires_at' => null,
            'restrict_to' => [self::PRO_PRICE],
        ]])->plans();

        $promo = $this->plan($plans, 'pro')['promo'];
        self::assertNotNull($promo);
        self::assertSame('flat', $promo['type']);
        self::assertSame(100, $promo['amount']);
        self::assertSame(1400, $promo['finalAmount'], '1500 − 100 = 1400');
    }

    public function testFlatDiscountInWrongCurrencyIsIgnored(): void
    {
        $plans = $this->build([[
            'id' => 'dsc_3',
            'status' => 'active',
            'type' => 'flat',
            'amount' => '100',
            'currency_code' => 'USD',
            'restrict_to' => [self::PRO_PRICE],
        ]])->plans();

        self::assertNull($this->plan($plans, 'pro')['promo']);
    }

    public function testExpiredDiscountIsIgnored(): void
    {
        $plans = $this->build([[
            'id' => 'dsc_4',
            'status' => 'active',
            'type' => 'percentage',
            'amount' => '50',
            'expires_at' => (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM),
            'restrict_to' => [self::STARTER_PRICE],
        ]])->plans();

        self::assertNull($this->plan($plans, 'starter')['promo']);
    }

    public function testDiscountRestrictedToAnotherPriceIsIgnored(): void
    {
        $plans = $this->build([[
            'id' => 'dsc_5',
            'status' => 'active',
            'type' => 'percentage',
            'amount' => '30',
            'restrict_to' => ['pri_somethingelse'],
        ]])->plans();

        self::assertNull($this->plan($plans, 'starter')['promo']);
        self::assertNull($this->plan($plans, 'pro')['promo']);
    }

    public function testGlobalDiscountAppliesToAllPrices(): void
    {
        $plans = $this->build([[
            'id' => 'dsc_6',
            'status' => 'active',
            'type' => 'percentage',
            'amount' => '10',
            'restrict_to' => null,
        ]])->plans();

        self::assertSame(270, $this->plan($plans, 'starter')['promo']['finalAmount']);
        self::assertSame(1350, $this->plan($plans, 'pro')['promo']['finalAmount']);
    }

    public function testBestDiscountWins(): void
    {
        $plans = $this->build([
            ['id' => 'a', 'status' => 'active', 'type' => 'percentage', 'amount' => '10', 'restrict_to' => [self::STARTER_PRICE]],
            ['id' => 'b', 'status' => 'active', 'type' => 'percentage', 'amount' => '30', 'restrict_to' => [self::STARTER_PRICE]],
        ])->plans();

        $promo = $this->plan($plans, 'starter')['promo'];
        self::assertSame(210, $promo['finalAmount'], 'picks the 30% discount (lowest final)');
        self::assertSame(30.0, $promo['amount']);
    }

    public function testInactiveDiscountIsIgnored(): void
    {
        $plans = $this->build([[
            'id' => 'dsc_7',
            'status' => 'archived',
            'type' => 'percentage',
            'amount' => '40',
            'restrict_to' => [self::STARTER_PRICE],
        ]])->plans();

        self::assertNull($this->plan($plans, 'starter')['promo']);
    }

    public function testProductScopedDiscountApplies(): void
    {
        // A discount restricted to the PRODUCT (not the price) must still apply —
        // Paddle charges it, so the page must reflect it (no surprise-at-checkout).
        $plans = $this->build([[
            'id' => 'dsc_prod',
            'status' => 'active',
            'type' => 'percentage',
            'amount' => '25',
            'restrict_to' => [self::STARTER_PRODUCT],
        ]])->plans();

        $promo = $this->plan($plans, 'starter')['promo'];
        self::assertNotNull($promo, 'product-scoped discount must be detected');
        self::assertSame(225, $promo['finalAmount'], '300 − 25% = 225');

        self::assertNull($this->plan($plans, 'pro')['promo'], 'scoped to the starter product only');
    }

    public function testDiscountFetchFailureStillPricesPlansWithoutPromo(): void
    {
        $plans = $this->build(discountsFail: true)->plans();

        // Prices still render (they succeeded); promos are simply absent rather
        // than guessed — a missing promo is safe, a wrong price is not.
        self::assertTrue($this->plan($plans, 'starter')['available']);
        self::assertSame(300, $this->plan($plans, 'starter')['amount']);
        self::assertNull($this->plan($plans, 'starter')['promo']);
        self::assertNull($this->plan($plans, 'pro')['promo']);
    }

    public function testFailSafeWhenPriceUnreadable(): void
    {
        $plans = $this->build(proFails: true)->plans();

        $pro = $this->plan($plans, 'pro');
        self::assertFalse($pro['available'], 'unreadable price → unavailable, never a wrong number');
        self::assertNull($pro['amount']);
        // The plan limit is still known (it comes from config, not Paddle).
        self::assertSame(1000, $pro['codeLimit']);

        // The healthy plan is unaffected.
        self::assertTrue($this->plan($plans, 'starter')['available']);
    }

    public function testCatalogueIsCachedAndInvalidatable(): void
    {
        $catalog = $this->build(priceCalls: $priceCalls);

        $catalog->plans();
        $afterFirst = $priceCalls;
        self::assertSame(2, $afterFirst, 'two prices fetched on the first (cold) call');

        $catalog->plans();
        self::assertSame($afterFirst, $priceCalls, 'second call is served from cache — no new Paddle calls');

        $catalog->invalidate();
        $catalog->plans();
        self::assertSame($afterFirst * 2, $priceCalls, 'after invalidation the catalogue is rebuilt');
    }
}
