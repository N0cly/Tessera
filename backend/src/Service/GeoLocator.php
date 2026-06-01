<?php

declare(strict_types=1);

namespace App\Service;

use GeoIp2\Database\Reader;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * IP → ISO 3166-1 alpha-2 country code, via the local MaxMind GeoLite2 DB.
 *
 * Privacy: this is the ONLY place IPs are touched. The caller passes the IP
 * in, gets a country (or null) back, and discards the IP. We never log it,
 * never persist it, never forward it.
 *
 * Gracefully degrades when the DB file is missing or unreadable — the rest
 * of the stack still works, scans just won't have a country set.
 */
final class GeoLocator
{
    private ?Reader $reader = null;
    private bool $attempted = false;

    public function __construct(
        #[Autowire('%env(default::GEOIP_DATABASE_PATH)%')]
        private readonly ?string $databasePath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function countryFor(string $ip): ?string
    {
        $reader = $this->reader();
        if (null === $reader) {
            return null;
        }

        try {
            $record = $reader->country($ip);

            return $record->country->isoCode;
        } catch (\Throwable) {
            // Private IPs, addresses not in the DB, malformed input — all
            // expected. Return null rather than failing the whole handler.
            return null;
        }
    }

    private function reader(): ?Reader
    {
        if ($this->attempted) {
            return $this->reader;
        }
        $this->attempted = true;

        if (null === $this->databasePath || '' === $this->databasePath || !is_file($this->databasePath)) {
            $this->logger->info('GeoLite2 database not found, country lookups disabled.', [
                'path' => $this->databasePath,
            ]);

            return null;
        }

        try {
            $this->reader = new Reader($this->databasePath);
        } catch (\Throwable $e) {
            $this->logger->warning('GeoLite2 database failed to open.', [
                'path' => $this->databasePath,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->reader;
    }
}
