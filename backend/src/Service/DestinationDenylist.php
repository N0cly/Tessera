<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tells callers whether a destination URL's host is forbidden on this
 * instance. The list lives in an env var (comma- or whitespace-separated),
 * empty by default — we ship a mechanism, not a policy.
 *
 * Match rules:
 *   - "example.com"   → exact host match only.
 *   - ".example.com"  → matches example.com itself AND any subdomain.
 *
 * Comparison is case-insensitive and ignores port. Punycode is left as-is;
 * operators denylisting IDNs should write the punycode form.
 */
final class DestinationDenylist
{
    /** @var list<string>|null */
    private ?array $rules = null;

    public function __construct(
        #[Autowire('%env(default::LINK_DESTINATION_DENYLIST)%')]
        private readonly ?string $raw,
    ) {
    }

    public function isDenied(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return false;
        }
        $host = strtolower($host);

        foreach ($this->rules() as $rule) {
            if (str_starts_with($rule, '.')) {
                $bare = substr($rule, 1);
                if ($host === $bare || str_ends_with($host, $rule)) {
                    return true;
                }
            } elseif ($host === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function rules(): array
    {
        if (null !== $this->rules) {
            return $this->rules;
        }

        $raw = $this->raw ?? '';
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        $rules = [];
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if ('' !== $p) {
                $rules[] = $p;
            }
        }

        return $this->rules = array_values(array_unique($rules));
    }
}
