<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Destination hosts the public demo may REALLY redirect to
 * (tessera-demo-real-redirects.md). Only consulted in DEMO_MODE, and only for
 * seeded links — every other demo redirect stays an interstitial.
 *
 * The list lives in DEMO_REDIRECT_ALLOWLIST (comma- or whitespace-separated).
 * Matching is an EXACT, case-insensitive host compare (port ignored): no
 * wildcard or subdomain rule, so "nocly.fr" never lets "evil.nocly.fr" through.
 * Empty list = no real redirect at all in demo (the previous behaviour).
 */
final class DemoRedirectAllowlist
{
    /** @var list<string>|null */
    private ?array $hosts = null;

    public function __construct(
        #[Autowire('%env(default::DEMO_REDIRECT_ALLOWLIST)%')]
        private readonly ?string $raw,
    ) {
    }

    public function allows(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }
        if (!is_string($host) || '' === $host) {
            return false;
        }

        return in_array(rtrim(strtolower($host), '.'), $this->hosts(), true);
    }

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        if (null !== $this->hosts) {
            return $this->hosts;
        }

        $hosts = [];
        foreach (preg_split('/[\s,]+/', $this->raw ?? '') ?: [] as $h) {
            $h = strtolower(trim($h));
            if ('' !== $h) {
                $hosts[] = $h;
            }
        }

        return $this->hosts = array_values(array_unique($hosts));
    }
}
