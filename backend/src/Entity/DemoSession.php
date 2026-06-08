<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DemoSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One ephemeral demo workspace (tessera-demo-mode.md). Created anonymously on
 * entering the demo — NO signup, NO email/password from the visitor, NO PII.
 *
 * Each session is backed by a per-session synthetic `User`: the visitor's demo
 * data (links, scans) is owned by that user, so the platform's existing
 * owner-scoping guarantees a session can never see or modify another session's
 * data. The whole workspace (this row + the synthetic user + their links/scans)
 * is purged after `DEMO_SESSION_TTL_HOURS` of inactivity; deleting the user
 * cascades the rest.
 */
#[ORM\Entity(repositoryClass: DemoSessionRepository::class)]
#[ORM\Table(name: 'demo_sessions')]
#[ORM\Index(name: 'idx_demo_last_activity', columns: ['last_activity_at'])]
class DemoSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Opaque session token (also the JWT subject's binding); not guessable. */
    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    /** The ephemeral user that owns this session's demo data (purged with it). */
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastActivityAt;

    public function __construct(User $user, string $token)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->token = $token;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastActivityAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastActivityAt(): \DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function touch(?\DateTimeImmutable $now = null): self
    {
        $this->lastActivityAt = $now ?? new \DateTimeImmutable();

        return $this;
    }

    /** Whether the session has been idle past the reset window. */
    public function isStale(int $ttlHours, ?\DateTimeImmutable $now = null): bool
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->modify(sprintf('-%d hours', max(1, $ttlHours)));

        return $this->lastActivityAt < $cutoff;
    }
}
