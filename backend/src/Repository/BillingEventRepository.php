<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BillingEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingEvent>
 */
class BillingEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingEvent::class);
    }

    public function alreadyProcessed(string $providerEventId): bool
    {
        return null !== $this->findOneBy(['providerEventId' => $providerEventId]);
    }
}
