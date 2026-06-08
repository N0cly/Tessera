<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DemoSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemoSession>
 */
class DemoSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemoSession::class);
    }

    public function findOneByToken(string $token): ?DemoSession
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function findOneByUser(User $user): ?DemoSession
    {
        return $this->findOneBy(['user' => $user]);
    }

    /** Count of currently-existing demo sessions (concurrent-session cap). */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Slugs of every link owned by a session idle past the cutoff. Collected
     * BEFORE purgeStale() deletes them so the caller can bust the redirect cache
     * (the bulk DELETE cascades at the DB level and won't fire ORM listeners).
     *
     * @return string[]
     */
    public function findSlugsForStaleSessions(\DateTimeImmutable $cutoff): array
    {
        $slugs = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
            SELECT l.slug FROM links l
            WHERE l.owner_id IN (
                SELECT user_id FROM demo_sessions WHERE last_activity_at < :cutoff
            )
            SQL,
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );

        return array_map('strval', $slugs);
    }

    /**
     * Delete the synthetic users of sessions idle past the cutoff. The DB
     * cascades (FK onDelete CASCADE) the demo_sessions rows, links and scans, so
     * a single statement reaps the whole stale workspace.
     *
     * @return int number of stale workspaces purged
     */
    public function purgeStale(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
            DELETE FROM users
            WHERE id IN (
                SELECT user_id FROM demo_sessions WHERE last_activity_at < :cutoff
            )
            SQL,
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
