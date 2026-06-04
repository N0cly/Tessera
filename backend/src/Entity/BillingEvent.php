<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BillingEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Ledger of provider webhook events we have already applied. The provider's
 * event id is unique — re-delivery of the same event is a no-op, which is what
 * makes the billing webhook idempotent (CLAUDE.md rule 14).
 */
#[ORM\Entity(repositoryClass: BillingEventRepository::class)]
#[ORM\Table(name: 'billing_events')]
#[ORM\UniqueConstraint(name: 'uniq_billing_event_provider_id', columns: ['provider_event_id'])]
class BillingEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255, unique: true)]
    private string $providerEventId;

    #[ORM\Column(length: 64)]
    private string $eventType;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    public function __construct(string $providerEventId, string $eventType)
    {
        $this->id = Uuid::v7();
        $this->providerEventId = $providerEventId;
        $this->eventType = $eventType;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getProviderEventId(): string
    {
        return $this->providerEventId;
    }
}
