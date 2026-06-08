<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only security audit trail for the operator admin panel: every admin
 * login attempt (success/failure) and every access to customer data is recorded
 * here (CLAUDE.md rule 17 / tessera-admin-panel.md).
 *
 * Note: the `ip` stored here is the OPERATOR's own address for security audit —
 * this is distinct from the platform's hard rule that visitor/scanner IPs are
 * never persisted (rule 5). No customer/scanner identity is ever recorded here.
 */
#[ORM\Entity(repositoryClass: AdminAuditLogRepository::class)]
#[ORM\Table(name: 'admin_audit_logs')]
#[ORM\Index(name: 'idx_admin_audit_created_at', columns: ['created_at'])]
class AdminAuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** The acting (or attempted) admin email. */
    #[ORM\Column(length: 180)]
    private string $actorEmail;

    /** A short action key, e.g. "admin.login.success", "admin.customers.view". */
    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column]
    private bool $success;

    /** The operator's request IP (security audit), null if unavailable. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $detail;

    /**
     * @param array<string, mixed>|null $detail
     */
    public function __construct(
        string $actorEmail,
        string $action,
        bool $success,
        ?string $ip = null,
        ?array $detail = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->actorEmail = $actorEmail;
        $this->action = $action;
        $this->success = $success;
        $this->ip = $ip;
        $this->detail = $detail;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActorEmail(): string
    {
        return $this->actorEmail;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDetail(): ?array
    {
        return $this->detail;
    }
}
