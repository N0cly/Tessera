<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Server-side authorization gate for the admin panel. Every admin endpoint MUST
 * call requireAdmin() first — hiding the UI is never sufficient (CLAUDE.md
 * rule 17 / tessera-admin-panel.md).
 *
 * Three independent checks, all fail-closed with a generic 403 (no detail that
 * could aid probing):
 *   1. the request IP is allow-listed (when an allowlist is configured);
 *   2. the authenticated principal is an operator admin (DB role or env allowlist);
 *   3. the token was minted by the 2FA admin-login flow (scope=admin, mfa=true) —
 *      so a plain user token, even an admin's, cannot reach admin data.
 */
final class AdminContext
{
    public function __construct(
        private readonly Security $security,
        private readonly AdminAccess $access,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function requireAdmin(): User
    {
        $token = $this->security->getToken();
        $user = $token?->getUser();
        $ip = $this->clientIp();

        $scope = $token && $token->hasAttribute('scope') ? $token->getAttribute('scope') : null;
        $mfa = $token && $token->hasAttribute('mfa') ? $token->getAttribute('mfa') : false;

        if (!$this->access->isIpAllowed($ip)
            || !$user instanceof User
            || !$this->access->isAdmin($user)
            || 'admin' !== $scope
            || true !== $mfa
        ) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $user;
    }

    public function clientIp(): ?string
    {
        return $this->requestStack->getCurrentRequest()?->getClientIp();
    }
}
