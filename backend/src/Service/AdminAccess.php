<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decides who counts as an operator admin and from where.
 *
 * Admin status is granted ONLY out-of-band (CLAUDE.md rule 17): either
 * `ROLE_ADMIN` set in the database (via the admin CLI) OR the account's email
 * listed in the `ADMIN_ALLOWLIST` env var. It is never assignable through
 * signup or any user-facing flow. 2FA is enforced separately at login.
 *
 * An optional `ADMIN_IP_ALLOWLIST` restricts admin access to known operator
 * addresses (empty = no IP restriction — mechanism only, like the destination
 * denylist).
 */
final class AdminAccess
{
    /** @var list<string> lower-cased allow-listed emails */
    private array $emailAllowlist;
    /** @var list<string> allow-listed exact IPs */
    private array $ipAllowlist;

    public function __construct(
        #[Autowire('%env(default::ADMIN_ALLOWLIST)%')]
        ?string $emailAllowlist,
        #[Autowire('%env(default::ADMIN_IP_ALLOWLIST)%')]
        ?string $ipAllowlist,
    ) {
        $this->emailAllowlist = $this->parse(strtolower((string) $emailAllowlist));
        $this->ipAllowlist = $this->parse((string) $ipAllowlist);
    }

    /**
     * Whether this account is an operator admin (DB role OR env allowlist).
     * Does NOT consider 2FA — that's enforced at the login gate.
     */
    public function isAdmin(User $user): bool
    {
        if ($user->hasAdminRole()) {
            return true;
        }

        return in_array(strtolower($user->getEmail()), $this->emailAllowlist, true);
    }

    /**
     * Whether requests from this IP may reach the admin surface. True when no
     * allowlist is configured (default) or the IP is explicitly listed.
     */
    public function isIpAllowed(?string $ip): bool
    {
        if ([] === $this->ipAllowlist) {
            return true;
        }

        return null !== $ip && in_array($ip, $this->ipAllowlist, true);
    }

    public function hasIpAllowlist(): bool
    {
        return [] !== $this->ipAllowlist;
    }

    /**
     * Allow-listed admin emails from env (for the admin:list CLI display).
     *
     * @return list<string>
     */
    public function emailAllowlist(): array
    {
        return $this->emailAllowlist;
    }

    /**
     * @return list<string>
     */
    private function parse(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));
    }
}
