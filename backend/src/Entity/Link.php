<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\LinkRepository;
use App\State\LinkProcessor;
use App\Validator\AllowedDestination;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LinkRepository::class)]
#[ORM\Table(name: 'links')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(
            security: "is_granted('ROLE_USER')",
            processor: LinkProcessor::class,
        ),
        new Get(security: "is_granted('ROLE_USER') and object.getOwner() == user"),
        new Patch(
            security: "is_granted('ROLE_USER') and object.getOwner() == user",
            processor: LinkProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_USER') and object.getOwner() == user"),
    ],
    normalizationContext: ['groups' => ['link:read']],
    denormalizationContext: ['groups' => ['link:write']],
    order: ['createdAt' => 'DESC'],
)]
class Link
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['link:read'])]
    private Uuid $id;

    #[ORM\Column(length: 32, unique: true)]
    #[Groups(['link:read'])]
    private ?string $slug = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
    #[AllowedDestination]
    #[Groups(['link:read', 'link:write'])]
    private ?string $destinationUrl = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Groups(['link:read', 'link:write'])]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['link:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['link:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDestinationUrl(): ?string
    {
        return $this->destinationUrl;
    }

    public function setDestinationUrl(string $destinationUrl): self
    {
        $this->destinationUrl = $destinationUrl;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
