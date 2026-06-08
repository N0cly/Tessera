<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Self-contained TOTP (RFC 6238) for admin two-factor auth — no external
 * dependency. Standard parameters compatible with Google Authenticator / Authy
 * / 1Password: SHA-1, 6 digits, 30-second period, base32 secret.
 *
 * Used only for admin accounts (CLAUDE.md rule 17 / tessera-admin-panel.md):
 * the CLI grants admin + generates the secret, the admin enrolls it in their
 * authenticator app, and /admin/login verifies the code before issuing an
 * admin-scoped token.
 */
final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // RFC 4648 base32
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGO = 'sha1';

    /**
     * Generate a fresh base32 secret (default 160 bits — the RFC-recommended
     * SHA-1 key size). Uses a CSPRNG.
     */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes(max(16, $bytes)));
    }

    /**
     * otpauth:// provisioning URI for QR enrollment in an authenticator app.
     */
    public function provisioningUri(string $secret, string $account, string $issuer = 'Tessera'): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        return sprintf('otpauth://totp/%s?%s', $label, $query);
    }

    /**
     * Verify a user-supplied code against the secret, allowing a ±$window step
     * drift for clock skew.
     */
    public function verify(string $secret, string $code, int $window = 1, ?int $now = null): bool
    {
        return null !== $this->matchStep($secret, $code, $window, $now);
    }

    /**
     * Return the time-step counter a valid code matched, or null if it doesn't
     * match any step in the ±$window. Callers use the returned step to enforce
     * single-use (reject codes whose step was already consumed). Constant-time
     * per candidate via hash_equals, with no early return so timing doesn't leak
     * which step matched.
     */
    public function matchStep(string $secret, string $code, int $window = 1, ?int $now = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now ??= time();
        $counter = intdiv($now, self::PERIOD);

        $matched = null;
        for ($offset = -$window; $offset <= $window; ++$offset) {
            if (hash_equals($this->hotp($secret, $counter + $offset), $code)) {
                $matched = $counter + $offset;
            }
        }

        return $matched;
    }

    /**
     * HOTP value (RFC 4226) for a counter, zero-padded to DIGITS.
     */
    private function hotp(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        if ('' === $key) {
            return str_repeat('?', self::DIGITS); // never matches a numeric code
        }

        $binCounter = pack('N*', 0).pack('N*', $counter); // 64-bit big-endian
        $hash = hash_hmac(self::ALGO, $binCounter, $key, true);

        $by = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((ord($hash[$by]) & 0x7F) << 24)
            | ((ord($hash[$by + 1]) & 0xFF) << 16)
            | ((ord($hash[$by + 2]) & 0xFF) << 8)
            | (ord($hash[$by + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $out = '';
        $buffer = 0;
        $bitsLeft = 0;
        foreach (str_split($data) as $char) {
            $buffer = ($buffer << 8) | ord($char);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $out .= self::ALPHABET[($buffer >> $bitsLeft) & 0x1F];
            }
        }
        if ($bitsLeft > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $out;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ('' === $secret) {
            return '';
        }

        $out = '';
        $buffer = 0;
        $bitsLeft = 0;
        $length = strlen($secret);
        for ($i = 0; $i < $length; ++$i) {
            $value = strpos(self::ALPHABET, $secret[$i]);
            if (false === $value) {
                return '';
            }
            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $out .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $out;
    }
}
