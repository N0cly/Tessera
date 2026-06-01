<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ScanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row per /r/{slug} hit. Privacy by design: the raw IP is NEVER stored —
 * it lives only in the in-flight Messenger message, gets resolved to a country,
 * then is discarded by the handler before persistence.
 */
#[ORM\Entity(repositoryClass: ScanRepository::class)]
#[ORM\Table(name: 'scans')]
#[ORM\Index(columns: ['link_id', 'scanned_at'], name: 'idx_scan_link_time')]
class Scan
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Link::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Link $link;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $scannedAt;

    /** ISO 3166-1 alpha-2 country code, or null if GeoLite2 lookup failed. */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $country = null;

    /** e.g. "smartphone", "desktop", "tablet" — null if device-detector couldn't classify. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $device = null;

    /** e.g. "iOS", "Android", "Windows". */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $os = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $referrer = null;

    public function __construct(Link $link, \DateTimeImmutable $scannedAt)
    {
        $this->id = Uuid::v7();
        $this->link = $link;
        $this->scannedAt = $scannedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLink(): Link
    {
        return $this->link;
    }

    public function getScannedAt(): \DateTimeImmutable
    {
        return $this->scannedAt;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;

        return $this;
    }

    public function getDevice(): ?string
    {
        return $this->device;
    }

    public function setDevice(?string $device): self
    {
        $this->device = $device;

        return $this;
    }

    public function getOs(): ?string
    {
        return $this->os;
    }

    public function setOs(?string $os): self
    {
        $this->os = $os;

        return $this;
    }

    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    public function setReferrer(?string $referrer): self
    {
        $this->referrer = $referrer;

        return $this;
    }
}
