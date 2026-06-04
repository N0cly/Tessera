<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\SubscriptionStatus;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Owns the lifecycle of a user's Subscription: provisioning the free trial at
 * sign-up and applying provider state from verified webhooks. Access is granted
 * here and from webhooks ONLY — never from a checkout return redirect.
 */
final class SubscriptionManager
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(int:BILLING_TRIAL_DAYS)%')]
        private readonly int $trialDays,
    ) {
    }

    /**
     * Return the user's subscription, lazily provisioning a free trial if none
     * exists yet (covers users created before this milestone). The trial clock
     * starts from the account creation date so back-fill is fair.
     */
    public function getOrCreate(User $user): Subscription
    {
        $subscription = $this->subscriptions->findOneByUser($user);
        if (null !== $subscription) {
            return $subscription;
        }

        $subscription = $this->newTrial($user, $user->getCreatedAt());
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    /**
     * Build a fresh trialing subscription. Caller persists. Used by
     * registration so a new account is immediately on a 14-day trial.
     */
    public function newTrial(User $user, ?\DateTimeImmutable $from = null): Subscription
    {
        $from ??= new \DateTimeImmutable();

        return (new Subscription($user))
            ->setPlan(PlanCatalog::PLAN_FREE_TRIAL)
            ->setStatus(SubscriptionStatus::Trialing)
            ->setTrialEndsAt($from->modify(sprintf('+%d days', $this->trialDays)));
    }

    /**
     * Apply provider subscription state (from a verified webhook) to the
     * subscription and persist. Idempotent at the field level — re-applying the
     * same values is harmless.
     */
    public function applyProviderState(
        Subscription $subscription,
        SubscriptionStatus $status,
        ?string $plan,
        ?string $providerSubscriptionId,
        ?string $providerCustomerId,
        ?\DateTimeImmutable $currentPeriodEndsAt,
    ): void {
        $subscription->setStatus($status);
        if (null !== $plan) {
            $subscription->setPlan($plan);
        }
        if (null !== $providerSubscriptionId) {
            $subscription->setProviderSubscriptionId($providerSubscriptionId);
        }
        if (null !== $providerCustomerId) {
            $subscription->setProviderCustomerId($providerCustomerId);
        }
        if (null !== $currentPeriodEndsAt) {
            $subscription->setCurrentPeriodEndsAt($currentPeriodEndsAt);
        }
        $subscription->touch();

        $this->em->persist($subscription);
        $this->em->flush();
    }
}
