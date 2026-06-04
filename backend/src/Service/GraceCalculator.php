<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns a subscription's status + dates into the single instant the redirect
 * hot path needs: when (if ever) a code should switch from its destination to
 * its fallback. Computed once at cache-build time so /r/{slug} decides with a
 * plain timestamp compare and no subscription join (CLAUDE.md rule 15).
 *
 * Grace: after the current period ends, codes keep working for
 * BILLING_GRACE_DAYS (default 30) before falling back — honest degradation,
 * not an abrupt break.
 */
final class GraceCalculator
{
    /** Far-past sentinel: "already lapsed, switch immediately". */
    private const ALREADY_LAPSED = '@0';

    public function __construct(
        #[Autowire('%env(int:BILLING_GRACE_DAYS)%')]
        private readonly int $graceDays,
    ) {
    }

    public function days(): int
    {
        return $this->graceDays;
    }

    /**
     * The instant at/after which the code switches to its fallback, or null if
     * it never switches (the owner is on an active plan / free trial).
     *
     *  - trialing / active                       → null  (never switches)
     *  - past_due / canceled, period end known   → periodEnd + grace
     *  - past_due / canceled, period end unknown → null  (lenient: keep serving
     *    the destination until we actually know grace has elapsed)
     *  - expired                                 → periodEnd + grace, else now
     */
    public function graceEndsAt(?Subscription $subscription): ?\DateTimeImmutable
    {
        if (null === $subscription) {
            return null; // No billing record → treat as entitled (free self-host).
        }

        $periodEnd = $subscription->getCurrentPeriodEndsAt();

        return match ($subscription->getStatus()) {
            SubscriptionStatus::Trialing, SubscriptionStatus::Active => null,
            SubscriptionStatus::PastDue, SubscriptionStatus::Canceled => null !== $periodEnd
                ? $periodEnd->modify(sprintf('+%d days', $this->graceDays))
                : null,
            SubscriptionStatus::Expired => null !== $periodEnd
                ? $periodEnd->modify(sprintf('+%d days', $this->graceDays))
                : new \DateTimeImmutable(self::ALREADY_LAPSED),
        };
    }

    /**
     * Whether the owner's subscription is on a lapsing trajectory (informational
     * flag stored alongside graceEndsAt; the switch itself is time-driven).
     */
    public function isLapsing(?Subscription $subscription): bool
    {
        if (null === $subscription) {
            return false;
        }

        return match ($subscription->getStatus()) {
            SubscriptionStatus::Trialing, SubscriptionStatus::Active => false,
            SubscriptionStatus::PastDue, SubscriptionStatus::Canceled, SubscriptionStatus::Expired => true,
        };
    }
}
