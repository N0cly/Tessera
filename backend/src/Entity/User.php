<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity(fields: ['email'], message: 'An account with this email already exists.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    /**
     * Base32 TOTP secret for two-factor auth. Only ever set for admin accounts
     * (via the admin CLI) — 2FA is mandatory to reach the operator panel. Null
     * for ordinary users. Never exposed over the API.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $totpSecret = null;

    /**
     * The last successfully-consumed TOTP time-step. A code is accepted only if
     * its step is strictly greater than this, making each code single-use within
     * its validity window (RFC 6238 §5.2 replay protection). Admin-only.
     */
    #[ORM\Column(nullable: true)]
    private ?int $lastTotpStep = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): self
    {
        $this->totpSecret = $totpSecret;

        return $this;
    }

    public function isTotpEnabled(): bool
    {
        return null !== $this->totpSecret && '' !== $this->totpSecret;
    }

    public function getLastTotpStep(): ?int
    {
        return $this->lastTotpStep;
    }

    public function setLastTotpStep(?int $lastTotpStep): self
    {
        $this->lastTotpStep = $lastTotpStep;

        return $this;
    }

    /**
     * Whether ROLE_ADMIN is set on this account in the database. Note: admin
     * authorization additionally honours the env allowlist and requires 2FA —
     * always go through App\Service\AdminAccess, never this alone.
     */
    public function hasAdminRole(): bool
    {
        return in_array('ROLE_ADMIN', $this->getRoles(), true);
    }

    public function eraseCredentials(): void
    {
    }
}
