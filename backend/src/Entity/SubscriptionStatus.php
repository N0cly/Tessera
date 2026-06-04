<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Lifecycle of a user's subscription. The Merchant of Record (Paddle) is the
 * source of truth — these values are set only from verified webhooks (and the
 * initial `trialing` granted at registration).
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /**
     * Whether this status currently entitles the user to the paid plan's
     * allowances. past_due is treated as a grace period (still entitled) so a
     * transient failed payment doesn't instantly lock the account — dunning is
     * the MoR's job.
     */
    public function isEntitled(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue => true,
            self::Canceled, self::Expired => false,
        };
    }
}
