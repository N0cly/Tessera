<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Scan;
use App\Message\ScanRecorded;
use App\Repository\LinkRepository;
use App\Service\GeoLocator;
use DeviceDetector\DeviceDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class ScanRecordedHandler
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly EntityManagerInterface $em,
        private readonly GeoLocator $geo,
    ) {
    }

    public function __invoke(ScanRecorded $message): void
    {
        $link = $this->links->find(Uuid::fromString($message->linkId));
        if (null === $link) {
            // Link was deleted between dispatch and consume — drop silently.
            return;
        }

        $scannedAt = new \DateTimeImmutable($message->scannedAtIso);
        $scan = new Scan($link, $scannedAt);

        if (null !== $message->userAgent && '' !== $message->userAgent) {
            [$device, $os] = $this->parseUserAgent($message->userAgent);
            $scan->setDevice($device);
            $scan->setOs($os);
        }

        if (null !== $message->ip && '' !== $message->ip) {
            $scan->setCountry($this->geo->countryFor($message->ip));
        }

        if (null !== $message->referrer && '' !== $message->referrer) {
            $scan->setReferrer($message->referrer);
        }

        $this->em->persist($scan);
        $this->em->flush();

        // Defensive: don't leak the IP anywhere by accident.
        unset($message);
    }

    /**
     * @return array{0: ?string, 1: ?string} [device, os]
     */
    private function parseUserAgent(string $ua): array
    {
        $dd = new DeviceDetector($ua);
        $dd->parse();

        $device = $dd->getDeviceName();
        $device = '' !== $device ? $device : null;

        $os = $dd->getOs('name');
        $os = is_string($os) && '' !== $os ? $os : null;

        return [$device, $os];
    }
}
