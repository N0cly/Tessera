<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AdminAuditLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes the admin security audit trail: admin login attempts and any access to
 * customer data (CLAUDE.md rule 17). Append-only; never updated or deleted from
 * the app.
 */
final class AdminAuditLogger
{
    public const LOGIN_SUCCESS = 'admin.login.success';
    public const LOGIN_FAILURE = 'admin.login.failure';
    public const OVERVIEW_VIEW = 'admin.overview.view';
    public const CUSTOMERS_VIEW = 'admin.customers.view';
    public const AUDIT_VIEW = 'admin.audit.view';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed>|null $detail
     */
    public function log(
        string $action,
        string $actorEmail,
        bool $success,
        ?string $ip,
        ?array $detail = null,
    ): void {
        // Truncate to the column widths so attacker-controlled input (e.g. an
        // oversized attempted email on a failed login) can never overflow the
        // column and turn the write into a 500 / lose the audit record.
        $this->em->persist(new AdminAuditLog(
            mb_substr($actorEmail, 0, 180),
            mb_substr($action, 0, 64),
            $success,
            null !== $ip ? mb_substr($ip, 0, 45) : null,
            $detail,
        ));
        $this->em->flush();
    }
}
