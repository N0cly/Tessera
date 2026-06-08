<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Platform-wide aggregates for the admin panel, straight from our database —
 * this is the data Paddle can't give (product usage + customer shape). Computed
 * on the fly with SQL GROUP BY, no roll-up tables (same approach as the user
 * dashboard, but platform-wide). Privacy-first: the aggregate methods expose NO
 * PII; the two methods that do (customerList / topCustomersByUsage) are only
 * called from the audit-logged customers endpoint (CLAUDE.md rule 17).
 */
final class AdminStats
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    // ---- Usage (no PII) -------------------------------------------------

    public function totalLinks(): int
    {
        return (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM links');
    }

    public function totalScans(): int
    {
        return (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM scans');
    }

    /** Codes scanned at least once since $since — "active" (actually used) codes. */
    public function activeCodes(\DateTimeImmutable $since): int
    {
        return (int) $this->conn()->fetchOne(
            'SELECT COUNT(DISTINCT link_id) FROM scans WHERE scanned_at >= :since',
            ['since' => $since->format('Y-m-d H:i:s')],
        );
    }

    public function scansBetween(\DateTimeImmutable $since, \DateTimeImmutable $until): int
    {
        return (int) $this->conn()->fetchOne(
            'SELECT COUNT(*) FROM scans WHERE scanned_at >= :since AND scanned_at < :until',
            ['since' => $since->format('Y-m-d H:i:s'), 'until' => $until->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Platform-wide scans per day in [since, today], zero-filled.
     *
     * @return list<array{date: string, scans: int}>
     */
    public function scansPerDay(\DateTimeImmutable $since): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            <<<'SQL'
            SELECT to_char(date_trunc('day', scanned_at), 'YYYY-MM-DD') AS day, COUNT(*) AS count
            FROM scans
            WHERE scanned_at >= :since
            GROUP BY day
            ORDER BY day
            SQL,
            ['since' => $since->format('Y-m-d 00:00:00')],
        );

        return $this->zeroFill($rows, $since, 'scans');
    }

    // ---- Customers (aggregates, no PII) ---------------------------------

    public function totalUsers(): int
    {
        return (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM users');
    }

    /**
     * Signups per day in [since, today], zero-filled.
     *
     * @return list<array{date: string, count: int}>
     */
    public function signupsPerDay(\DateTimeImmutable $since): array
    {
        $rows = $this->conn()->fetchAllAssociative(
            <<<'SQL'
            SELECT to_char(date_trunc('day', created_at), 'YYYY-MM-DD') AS day, COUNT(*) AS count
            FROM users
            WHERE created_at >= :since
            GROUP BY day
            ORDER BY day
            SQL,
            ['since' => $since->format('Y-m-d 00:00:00')],
        );

        return $this->zeroFill($rows, $since, 'count');
    }

    /**
     * Subscription counts by plan.
     *
     * @return array<string, int>
     */
    public function usersByPlan(): array
    {
        return $this->keyedCount('SELECT plan AS k, COUNT(*) AS c FROM subscriptions GROUP BY plan');
    }

    /**
     * Subscription counts by status.
     *
     * @return array<string, int>
     */
    public function usersByStatus(): array
    {
        return $this->keyedCount('SELECT status AS k, COUNT(*) AS c FROM subscriptions GROUP BY status');
    }

    /** Customers whose subscription has lapsed (canceled/expired). */
    public function churnedCount(): int
    {
        return (int) $this->conn()->fetchOne(
            "SELECT COUNT(*) FROM subscriptions WHERE status IN ('canceled', 'expired')",
        );
    }

    // ---- Customers (PII — audit-logged callers only) --------------------

    /**
     * Minimal-field customer list, newest first. Contains PII (email): callers
     * MUST audit-log the access.
     *
     * @return list<array{email: string, createdAt: string, plan: ?string, status: ?string, codeCount: int}>
     */
    public function customerList(int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $rows = $this->conn()->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                SELECT u.email,
                       u.created_at,
                       s.plan,
                       s.status,
                       (SELECT COUNT(*) FROM links l WHERE l.owner_id = u.id) AS code_count
                FROM users u
                LEFT JOIN subscriptions s ON s.user_id = u.id
                ORDER BY u.created_at DESC
                LIMIT %d OFFSET %d
                SQL,
                $limit,
                $offset,
            ),
        );

        return array_map(
            static fn (array $r): array => [
                'email' => (string) $r['email'],
                'createdAt' => (new \DateTimeImmutable((string) $r['created_at']))->format(\DateTimeInterface::ATOM),
                'plan' => null !== $r['plan'] ? (string) $r['plan'] : null,
                'status' => null !== $r['status'] ? (string) $r['status'] : null,
                'codeCount' => (int) $r['code_count'],
            ],
            $rows,
        );
    }

    /**
     * Top customers by scan usage in [since, today]. Contains PII (email):
     * callers MUST audit-log the access.
     *
     * @return list<array{email: string, links: int, scans: int}>
     */
    public function topCustomersByUsage(\DateTimeImmutable $since, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $rows = $this->conn()->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                SELECT u.email,
                       COUNT(DISTINCT l.id) AS links,
                       COUNT(sc.id) AS scans
                FROM links l
                JOIN users u ON u.id = l.owner_id
                LEFT JOIN scans sc ON sc.link_id = l.id AND sc.scanned_at >= :since
                GROUP BY u.id, u.email
                ORDER BY scans DESC, links DESC, u.email ASC
                LIMIT %d
                SQL,
                $limit,
            ),
            ['since' => $since->format('Y-m-d 00:00:00')],
        );

        return array_map(
            static fn (array $r): array => [
                'email' => (string) $r['email'],
                'links' => (int) $r['links'],
                'scans' => (int) $r['scans'],
            ],
            $rows,
        );
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    /**
     * @param list<array<string, mixed>> $rows rows of {day, count}
     *
     * @return list<array<string, string|int>>
     */
    private function zeroFill(array $rows, \DateTimeImmutable $since, string $valueKey): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['count'];
        }

        $series = [];
        $today = new \DateTimeImmutable('today');
        $cursor = new \DateTimeImmutable($since->format('Y-m-d'));
        while ($cursor <= $today) {
            $day = $cursor->format('Y-m-d');
            $series[] = ['date' => $day, $valueKey => $byDay[$day] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * @return array<string, int>
     */
    private function keyedCount(string $sql): array
    {
        $out = [];
        foreach ($this->conn()->fetchAllAssociative($sql) as $row) {
            $out[(string) $row['k']] = (int) $row['c'];
        }

        return $out;
    }
}
