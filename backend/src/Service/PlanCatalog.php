<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Maps a plan to its allowances. Limits are operator-tunable via env so a
 * self-hoster can run effectively without caps. A null limit means unlimited.
 */
final class PlanCatalog
{
    public const PLAN_FREE_TRIAL = 'free_trial';
    public const PLAN_PRO = 'pro';

    public function __construct(
        #[Autowire('%env(int:PLAN_TRIAL_CODE_LIMIT)%')]
        private readonly int $trialCodeLimit,
        #[Autowire('%env(int:PLAN_PRO_CODE_LIMIT)%')]
        private readonly int $proCodeLimit,
    ) {
    }

    /**
     * Code limit for a plan, or null for unlimited (env value <= 0).
     */
    public function codeLimitForPlan(string $plan): ?int
    {
        $limit = match ($plan) {
            self::PLAN_PRO => $this->proCodeLimit,
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
            self::PLAN_FREE_TRIAL => 'Free trial',
            default => ucfirst(str_replace('_', ' ', $plan)),
        };
    }
}
