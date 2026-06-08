<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\User;
use App\Service\PlanCatalog;
use PHPUnit\Framework\TestCase;

final class PlanCatalogTest extends TestCase
{
    private function catalog(
        int $trial = 25,
        int $starter = 100,
        int $pro = 1000,
        ?string $starterPriceId = 'pri_starter',
        ?string $proPriceId = 'pri_pro',
        ?string $legacyPriceId = null,
    ): PlanCatalog {
        return new PlanCatalog($trial, $starter, $pro, $starterPriceId, $proPriceId, $legacyPriceId);
    }

    public function testCodeLimitPerPlan(): void
    {
        $c = $this->catalog(trial: 25, starter: 100, pro: 1000);

        self::assertSame(25, $c->codeLimitForPlan(PlanCatalog::PLAN_FREE_TRIAL));
        self::assertSame(100, $c->codeLimitForPlan(PlanCatalog::PLAN_STARTER));
        self::assertSame(1000, $c->codeLimitForPlan(PlanCatalog::PLAN_PRO));
    }

    public function testZeroOrLessMeansUnlimited(): void
    {
        $c = $this->catalog(pro: 0);

        self::assertNull($c->codeLimitForPlan(PlanCatalog::PLAN_PRO), '0 = unlimited');
    }

    public function testUnknownPlanFallsBackToTrialLimit(): void
    {
        $c = $this->catalog(trial: 7);

        self::assertSame(7, $c->codeLimitForPlan('mystery'));
    }

    public function testPriceIdMapping(): void
    {
        $c = $this->catalog(starterPriceId: 'pri_s', proPriceId: 'pri_p');

        self::assertSame('pri_s', $c->priceIdForPlan(PlanCatalog::PLAN_STARTER));
        self::assertSame('pri_p', $c->priceIdForPlan(PlanCatalog::PLAN_PRO));
        self::assertNull($c->priceIdForPlan(PlanCatalog::PLAN_FREE_TRIAL));
    }

    public function testLegacyPriceIdIsProFallbackWhenProUnset(): void
    {
        $c = $this->catalog(proPriceId: '', legacyPriceId: 'pri_legacy');

        self::assertSame('pri_legacy', $c->priceIdForPlan(PlanCatalog::PLAN_PRO));
    }

    public function testProPriceIdWinsOverLegacy(): void
    {
        $c = $this->catalog(proPriceId: 'pri_new', legacyPriceId: 'pri_legacy');

        self::assertSame('pri_new', $c->priceIdForPlan(PlanCatalog::PLAN_PRO));
    }

    public function testPlanForPriceIdIsReverseOfPriceIdForPlan(): void
    {
        $c = $this->catalog(starterPriceId: 'pri_s', proPriceId: 'pri_p');

        self::assertSame(PlanCatalog::PLAN_STARTER, $c->planForPriceId('pri_s'));
        self::assertSame(PlanCatalog::PLAN_PRO, $c->planForPriceId('pri_p'));
        self::assertNull($c->planForPriceId('pri_unknown'));
        self::assertNull($c->planForPriceId(''));
    }

    public function testHasConfiguredPlan(): void
    {
        self::assertTrue($this->catalog(starterPriceId: 'pri_s', proPriceId: '')->hasConfiguredPlan());
        self::assertFalse(
            $this->catalog(starterPriceId: '', proPriceId: '', legacyPriceId: '')->hasConfiguredPlan(),
        );
    }

    public function testEmptyPriceIdIsTreatedAsUnset(): void
    {
        $c = $this->catalog(starterPriceId: '');

        self::assertNull($c->priceIdForPlan(PlanCatalog::PLAN_STARTER));
    }

    public function testCodeLimitForLapsedSubscriptionDropsToTrial(): void
    {
        $c = $this->catalog(trial: 25, pro: 1000);

        $entitled = $this->subscription(PlanCatalog::PLAN_PRO, SubscriptionStatus::Active);
        $lapsed = $this->subscription(PlanCatalog::PLAN_PRO, SubscriptionStatus::Canceled);

        self::assertSame(1000, $c->codeLimitFor($entitled), 'active Pro keeps the Pro limit');
        self::assertSame(25, $c->codeLimitFor($lapsed), 'lapsed falls back to the trial limit');
    }

    public function testPaidPlansOrder(): void
    {
        self::assertSame(
            [PlanCatalog::PLAN_STARTER, PlanCatalog::PLAN_PRO],
            $this->catalog()->paidPlans(),
        );
        self::assertTrue($this->catalog()->isPaidPlan(PlanCatalog::PLAN_STARTER));
        self::assertFalse($this->catalog()->isPaidPlan(PlanCatalog::PLAN_FREE_TRIAL));
    }

    public function testDisplayNames(): void
    {
        $c = $this->catalog();

        self::assertSame('Starter', $c->displayName(PlanCatalog::PLAN_STARTER));
        self::assertSame('Pro', $c->displayName(PlanCatalog::PLAN_PRO));
        self::assertSame('Free trial', $c->displayName(PlanCatalog::PLAN_FREE_TRIAL));
    }

    private function subscription(string $plan, SubscriptionStatus $status): Subscription
    {
        $sub = new Subscription(new User());

        return $sub->setPlan($plan)->setStatus($status);
    }
}
