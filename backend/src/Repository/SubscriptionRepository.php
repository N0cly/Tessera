<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findOneByUser(User $user): ?Subscription
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findOneByProviderSubscriptionId(string $providerSubscriptionId): ?Subscription
    {
        return $this->findOneBy(['providerSubscriptionId' => $providerSubscriptionId]);
    }

    public function findOneByProviderCustomerId(string $providerCustomerId): ?Subscription
    {
        return $this->findOneBy(['providerCustomerId' => $providerCustomerId]);
    }

    // ---------------------------------------------------------------------
    // Admin panel: trial→paid conversion and churn. These need history, which
    // the live Paddle snapshot doesn't expose, so they are derived from our
    // webhook-synced Subscription mirror. (MRR and live counts still come from
    // Paddle — see AdminBillingMetrics.) Returned as ratios in [0, 1].
    // ---------------------------------------------------------------------

    /**
     * Of the trials that have ended, the share that became paying (currently
     * active/past_due, or have had a paid billing period). 0 when none ended.
     */
    public function trialConversionRate(): float
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE trial_ends_at IS NOT NULL AND trial_ends_at < now()) AS ended,
                COUNT(*) FILTER (
                    WHERE trial_ends_at IS NOT NULL AND trial_ends_at < now()
                      AND (status IN ('active', 'past_due') OR current_period_ends_at IS NOT NULL)
                ) AS converted
            FROM subscriptions
            SQL,
        ) ?: ['ended' => 0, 'converted' => 0];

        $ended = (int) $row['ended'];

        return $ended > 0 ? (int) $row['converted'] / $ended : 0.0;
    }

    /**
     * Share of the recently-active base that churned in the last 30 days
     * (canceled/expired with a recent status change). 0 when there is no base.
     */
    public function churnRateLast30d(): float
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*) FILTER (
                    WHERE status IN ('canceled', 'expired') AND updated_at >= now() - interval '30 days'
                ) AS churned,
                COUNT(*) FILTER (WHERE status IN ('active', 'past_due')) AS still_active
            FROM subscriptions
            SQL,
        ) ?: ['churned' => 0, 'still_active' => 0];

        $churned = (int) $row['churned'];
        $base = $churned + (int) $row['still_active'];

        return $base > 0 ? $churned / $base : 0.0;
    }
}

