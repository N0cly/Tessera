<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * THE single source of truth for plan business rules: the plan keys, their code
 * (link) limits, and the Paddle price id each paid plan checks out against.
 *
 * Everything that needs to know "how many codes does plan X allow" or "which
 * Paddle price is plan X" goes through here — the limit enforcement on link
 * creation (LinkProcessor), the pricing page (PricingCatalog / /api/pricing),
 * the checkout (BillingController) and the webhook plan mapping
 * (BillingWebhookController). No plan limit or price→plan mapping is duplicated
 * anywhere else (CLAUDE.md rules 8, 14, 16).
 *
 * Limits are operator-tunable via env so a self-hoster can run effectively
 * without caps. A limit of 0 (or less) means unlimited. Prices themselves are
 * NEVER stored here — they live in Paddle; we only hold the price *ids*.
 */
final class PlanCatalog
{
    public const PLAN_FREE_TRIAL = 'free_trial';
    public const PLAN_STARTER = 'starter';
    public const PLAN_PRO = 'pro';

    /** Paid plans, in the order they should be shown on the pricing page. */
    private const PAID_PLANS = [self::PLAN_STARTER, self::PLAN_PRO];

    public function __construct(
        #[Autowire('%env(int:PLAN_TRIAL_CODE_LIMIT)%')]
        private readonly int $trialCodeLimit,
        #[Autowire('%env(int:PLAN_STARTER_CODE_LIMIT)%')]
        private readonly int $starterCodeLimit,
        #[Autowire('%env(int:PLAN_PRO_CODE_LIMIT)%')]
        private readonly int $proCodeLimit,
        #[Autowire('%env(default::PADDLE_STARTER_PRICE_ID)%')]
        private readonly ?string $starterPriceId,
        #[Autowire('%env(default::PADDLE_PRO_PRICE_ID)%')]
        private readonly ?string $proPriceId,
        // Legacy single-price knob from the billing milestone. Kept as a
        // back-compat fallback for the Pro price so existing .env files keep
        // working after the per-plan ids were introduced.
        #[Autowire('%env(default::PADDLE_PRICE_ID)%')]
        private readonly ?string $legacyPriceId,
    ) {
    }

    /**
     * Paid plan keys in display order. Always returns both plans; whether each
     * is actually purchasable depends on its price id being configured
     * (see priceIdForPlan()).
     *
     * @return list<string>
     */
    public function paidPlans(): array
    {
        return self::PAID_PLANS;
    }

    public function isPaidPlan(string $plan): bool
    {
        return in_array($plan, self::PAID_PLANS, true);
    }

    /**
     * The Paddle price id a plan checks out against, or null if that plan has
     * no price configured on this instance (billing disabled / unset).
     */
    public function priceIdForPlan(string $plan): ?string
    {
        $id = match ($plan) {
            self::PLAN_STARTER => $this->starterPriceId,
            self::PLAN_PRO => '' !== (string) $this->proPriceId ? $this->proPriceId : $this->legacyPriceId,
            default => null,
        };

        $id = (string) $id;

        return '' !== $id ? $id : null;
    }

    /**
     * Reverse of priceIdForPlan(): given a Paddle price id (e.g. from a webhook
     * or checkout item), return the plan it maps to, or null if unknown.
     */
    public function planForPriceId(string $priceId): ?string
    {
        if ('' === $priceId) {
            return null;
        }

        foreach (self::PAID_PLANS as $plan) {
            if ($this->priceIdForPlan($plan) === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    /** Whether at least one paid plan has a Paddle price configured. */
    public function hasConfiguredPlan(): bool
    {
        foreach (self::PAID_PLANS as $plan) {
            if (null !== $this->priceIdForPlan($plan)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Code limit for a plan, or null for unlimited (env value <= 0).
     */
    public function codeLimitForPlan(string $plan): ?int
    {
        $limit = match ($plan) {
            self::PLAN_PRO => $this->proCodeLimit,
            self::PLAN_STARTER => $this->starterCodeLimit,
            default => $this->trialCodeLimit,
        };

        return $limit > 0 ? $limit : null;
    }

    /**
     * The code limit that actually applies to a subscription right now. A
     * lapsed (canceled/expired) subscription falls back to the trial limit so
     * existing codes keep working but new creation is capped at the free tier.
     */
    public function codeLimitFor(Subscription $subscription): ?int
    {
        $plan = $subscription->getStatus()->isEntitled()
            ? $subscription->getPlan()
            : self::PLAN_FREE_TRIAL;

        return $this->codeLimitForPlan($plan);
    }

    public function displayName(string $plan): string
    {
        return match ($plan) {
            self::PLAN_PRO => 'Pro',
            self::PLAN_STARTER => 'Starter',
            self::PLAN_FREE_TRIAL => 'Free trial',
            default => ucfirst(str_replace('_', ' ', $plan)),
        };
    }
}
