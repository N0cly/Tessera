<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    // RFC 6238 SHA-1 test seed "12345678901234567890" in base32.
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * The 6-digit truncations of the official RFC 6238 (SHA-1) test vectors.
     *
     * @return list<array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
            [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function testMatchesRfc6238Vectors(int $time, string $code): void
    {
        $totp = new TotpService();

        self::assertTrue($totp->verify(self::RFC_SECRET, $code, 0, $time), "code $code at T=$time");
    }

    public function testRejectsWrongCode(): void
    {
        $totp = new TotpService();

        self::assertFalse($totp->verify(self::RFC_SECRET, '000000', 0, 59));
        self::assertFalse($totp->verify(self::RFC_SECRET, '287083', 0, 59));
    }

    public function testAcceptsAdjacentStepWithinWindow(): void
    {
        $totp = new TotpService();

        // 287082 is the code for the step at T=59; it must still verify one step
        // later (T=89) with the ±1 drift window, but not with window 0.
        self::assertTrue($totp->verify(self::RFC_SECRET, '287082', 1, 89));
        self::assertFalse($totp->verify(self::RFC_SECRET, '287082', 0, 89));
    }

    public function testRejectsMalformedCodes(): void
    {
        $totp = new TotpService();

        self::assertFalse($totp->verify(self::RFC_SECRET, '123', 1, 59), 'too short');
        self::assertFalse($totp->verify(self::RFC_SECRET, 'abcdef', 1, 59), 'non-numeric');
        self::assertFalse($totp->verify(self::RFC_SECRET, '', 1, 59), 'empty');
    }

    public function testGenerateSecretIsValidBase32(): void
    {
        $totp = new TotpService();
        $secret = $totp->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        self::assertSame(32, strlen($secret), '20 bytes → 32 base32 chars');
    }

    public function testGeneratedSecretRoundTripsThroughVerify(): void
    {
        $totp = new TotpService();
        $secret = $totp->generateSecret();

        // Derive the current code via a second, independent calculation path
        // (verify at the same instant must accept exactly the matching step).
        $now = 1700000000;
        $code = $this->referenceCode($secret, $now);

        self::assertTrue($totp->verify($secret, $code, 0, $now));
        self::assertFalse($totp->verify($secret, $code, 0, $now + 60), 'expires after its step');
    }

    public function testMatchStepReturnsConsumedStepForReplayProtection(): void
    {
        $totp = new TotpService();

        // 287082 is the code for the step at T=59 → counter intdiv(59,30) = 1.
        self::assertSame(1, $totp->matchStep(self::RFC_SECRET, '287082', 0, 59));
        self::assertNull($totp->matchStep(self::RFC_SECRET, '000000', 0, 59));
        self::assertNull($totp->matchStep(self::RFC_SECRET, 'abc', 1, 59));
    }

    public function testProvisioningUri(): void
    {
        $uri = (new TotpService())->provisioningUri('ABC234', 'ops@example.com', 'Tessera');

        self::assertStringStartsWith('otpauth://totp/Tessera:ops%40example.com?', $uri);
        self::assertStringContainsString('secret=ABC234', $uri);
        self::assertStringContainsString('issuer=Tessera', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    /**
     * Independent TOTP computation used only to cross-check generated secrets.
     */
    private function referenceCode(string $base32, int $time): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($base32) as $c) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (8 === strlen($byte)) {
                $key .= chr((int) bindec($byte));
            }
        }

        $counter = intdiv($time, 30);
        $hash = hash_hmac('sha1', pack('N*', 0).pack('N*', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
