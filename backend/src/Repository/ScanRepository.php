<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Scan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Scan>
 */
class ScanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Scan::class);
    }

    public function countTotal(Uuid $linkId, \DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.link = :link')
            ->andWhere('s.scannedAt >= :since')
            ->setParameter('link', $linkId, 'uuid')
            ->setParameter('since', $since);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * One row per day in [since, today], filled with zero where no scans
     * happened — frontend doesn't have to handle gaps.
     *
     * @return list<array{date: string, count: int}>
     */
    public function countPerDay(Uuid $linkId, \DateTimeImmutable $since): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT to_char(date_trunc('day', scanned_at), 'YYYY-MM-DD') AS day,
                   COUNT(*) AS count
            FROM scans
            WHERE link_id = :link AND scanned_at >= :since
            GROUP BY day
            ORDER BY day
            SQL,
            [
                'link' => $linkId->toRfc4122(),
                'since' => $since->format('Y-m-d 00:00:00'),
            ],
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['day']] = (int) $row['count'];
        }

        $series = [];
        $today = new \DateTimeImmutable('today');
        $cursor = new \DateTimeImmutable($since->format('Y-m-d'));
        while ($cursor <= $today) {
            $day = $cursor->format('Y-m-d');
            $series[] = ['date' => $day, 'count' => $byDay[$day] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * @return list<array{country: string|null, count: int}>
     */
    public function countByCountry(Uuid $linkId, \DateTimeImmutable $since): array
    {
        return $this->groupedCount('country', $linkId, $since);
    }

    /**
     * @return list<array{device: string|null, count: int}>
     */
    public function countByDevice(Uuid $linkId, \DateTimeImmutable $since): array
    {
        return $this->groupedCount('device', $linkId, $since);
    }

    /**
     * @return list<array{os: string|null, count: int}>
     */
    public function countByOs(Uuid $linkId, \DateTimeImmutable $since): array
    {
        return $this->groupedCount('os', $linkId, $since);
    }

    // ---------------------------------------------------------------------
    // Account-level aggregations (dashboard overview). All scoped to the
    // owner via a JOIN on links.owner_id — computed on the fly, no roll-up
    // table (CLAUDE.md). Half-open ranges [since, until) keep the current and
    // previous period windows exactly equal in length.
    // ---------------------------------------------------------------------

    /**
     * Scans across all the owner's links within [since, until).
     */
    public function countForOwnerBetween(Uuid $ownerId, \DateTimeImmutable $since, \DateTimeImmutable $until): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
            SELECT COUNT(*)
            FROM scans s
            JOIN links l ON l.id = s.link_id
            WHERE l.owner_id = :owner AND s.scanned_at >= :since AND s.scanned_at < :until
            SQL,
            [
                'owner' => $ownerId->toRfc4122(),
                'since' => $since->format('Y-m-d H:i:s'),
                'until' => $until->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * All-time scan count across the owner's links.
     */
    public function countTotalForOwner(Uuid $ownerId): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
            SELECT COUNT(*)
            FROM scans s
            JOIN links l ON l.id = s.link_id
            WHERE l.owner_id = :owner
            SQL,
            ['owner' => $ownerId->toRfc4122()],
        );
    }

    /**
     * One entry per day in [since, today], zero-filled so the frontend chart
     * has no gaps.
     *
     * @return list<array{date: string, scans: int}>
     */
    public function scansPerDayForOwner(Uuid $ownerId, \DateTimeImmutable $since): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT to_char(date_trunc('day', s.scanned_at), 'YYYY-MM-DD') AS day,
                   COUNT(*) AS count
            FROM scans s
            JOIN links l ON l.id = s.link_id
            WHERE l.owner_id = :owner AND s.scanned_at >= :since
            GROUP BY day
            ORDER BY day
            SQL,
            [
                'owner' => $ownerId->toRfc4122(),
                'since' => $since->format('Y-m-d 00:00:00'),
            ],
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['day']] = (int) $row['count'];
        }

        $series = [];
        $today = new \DateTimeImmutable('today');
        $cursor = new \DateTimeImmutable($since->format('Y-m-d'));
        while ($cursor <= $today) {
            $day = $cursor->format('Y-m-d');
            $series[] = ['date' => $day, 'scans' => $byDay[$day] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * Top links by scans within [since, today], owner-scoped.
     *
     * @return list<array{slug: string, name: string|null, scans: int}>
     */
    public function topLinksForOwner(Uuid $ownerId, \DateTimeImmutable $since, int $limit = 5): array
    {
        // $limit is an internal int (cast below) — safe to interpolate; it is
        // never user-supplied. Keeps us off DBAL's enum-typed param binding.
        $sql = sprintf(
            <<<'SQL'
            SELECT l.slug, l.name, COUNT(s.id) AS scans
            FROM scans s
            JOIN links l ON l.id = s.link_id
            WHERE l.owner_id = :owner AND s.scanned_at >= :since
            GROUP BY l.id, l.slug, l.name
            ORDER BY scans DESC, l.slug ASC
            LIMIT %d
            SQL,
            $limit,
        );

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            [
                'owner' => $ownerId->toRfc4122(),
                'since' => $since->format('Y-m-d 00:00:00'),
            ],
        );

        return array_map(
            static fn (array $r) => [
                'slug' => (string) $r['slug'],
                'name' => null !== $r['name'] ? (string) $r['name'] : null,
                'scans' => (int) $r['scans'],
            ],
            $rows,
        );
    }

    /**
     * Device breakdown within [since, today] as percentages summing to ~100.
     * Null device (unclassifiable UA) is reported as "unknown".
     *
     * @return list<array{device: string, pct: int}>
     */
    public function deviceBreakdownForOwner(Uuid $ownerId, \DateTimeImmutable $since): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT COALESCE(s.device, 'unknown') AS device, COUNT(*) AS count
            FROM scans s
            JOIN links l ON l.id = s.link_id
            WHERE l.owner_id = :owner AND s.scanned_at >= :since
            GROUP BY COALESCE(s.device, 'unknown')
            ORDER BY count DESC, device ASC
            SQL,
            [
                'owner' => $ownerId->toRfc4122(),
                'since' => $since->format('Y-m-d 00:00:00'),
            ],
        );

        $total = array_sum(array_map(static fn (array $r) => (int) $r['count'], $rows));
        if (0 === $total) {
            return [];
        }

        return array_map(
            static fn (array $r) => [
                'device' => (string) $r['device'],
                'pct' => (int) round((int) $r['count'] / $total * 100),
            ],
            $rows,
        );
    }

    /**
     * @return list<array<string, string|int|null>>
     */
    private function groupedCount(string $column, Uuid $linkId, \DateTimeImmutable $since): array
    {
        // $column is internal and matches a list of validated field names.
        $allowed = ['country', 'device', 'os'];
        if (!\in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported grouping column "%s".', $column));
        }

        $qb = $this->createQueryBuilder('s')
            ->select(sprintf('s.%s AS %s, COUNT(s.id) AS count', $column, $column))
            ->where('s.link = :link')
            ->andWhere('s.scannedAt >= :since')
            ->setParameter('link', $linkId, 'uuid')
            ->setParameter('since', $since)
            ->groupBy('s.'.$column)
            ->orderBy('count', 'DESC');

        return array_map(
            static fn (array $r) => [$column => $r[$column], 'count' => (int) $r['count']],
            $qb->getQuery()->getArrayResult(),
        );
    }
}
