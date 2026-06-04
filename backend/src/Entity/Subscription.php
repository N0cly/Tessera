<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One subscription per user. We store only the *status* of the subscription
 * and the provider references needed to reconcile and deep-link the customer
 * portal — never card data, never tax info. The Merchant of Record (Paddle)
 * owns all of that; we react to its webhooks (see CLAUDE.md rule 14).
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscriptions')]
class Subscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    /** Plan key, e.g. "free_trial" or "pro". */
    #[ORM\Column(length: 32)]
    private string $plan = 'free_trial';

    #[ORM\Column(length: 16, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status = SubscriptionStatus::Trialing;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEndsAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerSubscriptionId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function setPlan(string $plan): self
    {
        $this->plan = $plan;

        return $this;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function setStatus(SubscriptionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): self
    {
        $this->trialEndsAt = $trialEndsAt;

        return $this;
    }

    public function getCurrentPeriodEndsAt(): ?\DateTimeImmutable
    {
        return $this->currentPeriodEndsAt;
    }

    public function setCurrentPeriodEndsAt(?\DateTimeImmutable $currentPeriodEndsAt): self
    {
        $this->currentPeriodEndsAt = $currentPeriodEndsAt;

        return $this;
    }

    public function getProviderCustomerId(): ?string
    {
        return $this->providerCustomerId;
    }

    public function setProviderCustomerId(?string $providerCustomerId): self
    {
        $this->providerCustomerId = $providerCustomerId;

        return $this;
    }

    public function getProviderSubscriptionId(): ?string
    {
        return $this->providerSubscriptionId;
    }

    public function setProviderSubscriptionId(?string $providerSubscriptionId): self
    {
        $this->providerSubscriptionId = $providerSubscriptionId;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Days remaining in the free trial (0 once it has lapsed or there is no
     * trial). Used by the dashboard's Plan & usage widget.
     */
    public function trialDaysLeft(?\DateTimeImmutable $now = null): int
    {
        if (null === $this->trialEndsAt) {
            return 0;
        }
        $now ??= new \DateTimeImmutable();
        if ($this->trialEndsAt <= $now) {
            return 0;
        }

        return (int) ceil(($this->trialEndsAt->getTimestamp() - $now->getTimestamp()) / 86400);
    }
}
