<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched by the redirect hot path. Carries everything the handler needs
 * to derive a Scan row, including the raw IP — which is dropped by the
 * handler after country lookup and never written to the database.
 *
 * Kept as a small immutable DTO so it serialises cleanly for the Redis
 * Messenger transport.
 */
final readonly class ScanRecorded
{
    public function __construct(
        public string $linkId,
        public string $scannedAtIso,
        public ?string $userAgent,
        public ?string $ip,
        public ?string $referrer,
    ) {
    }
}
