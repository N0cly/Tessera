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
