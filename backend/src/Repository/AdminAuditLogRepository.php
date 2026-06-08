<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminAuditLog>
 */
class AdminAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAuditLog::class);
    }

    /** Self-noise: viewing the audit log is recorded but excluded from the feed. */
    private const EXCLUDED_ACTION = 'admin.audit.view';

    /**
     * Most recent audit entries first (excluding audit-view self-events).
     *
     * @return list<AdminAuditLog>
     */
    public function recent(int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.action != :excluded')
            ->setParameter('excluded', self::EXCLUDED_ACTION)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getResult();
    }

    public function total(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.action != :excluded')
            ->setParameter('excluded', self::EXCLUDED_ACTION)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
