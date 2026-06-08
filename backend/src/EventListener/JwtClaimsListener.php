<?php

declare(strict_types=1);

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Copies the custom `scope` and `mfa` claims from a verified JWT onto the
 * security token so AdminContext can require them on every admin endpoint.
 *
 * Only the admin login (/admin/login) mints tokens with `scope=admin, mfa=true`
 * after verifying TOTP. An ordinary /api/login_check token — even for a user who
 * happens to hold ROLE_ADMIN — carries neither claim, so it can never reach the
 * admin surface. This is what makes 2FA non-bypassable (CLAUDE.md rule 17).
 */
#[AsEventListener(event: Events::JWT_AUTHENTICATED)]
final class JwtClaimsListener
{
    public function __invoke(JWTAuthenticatedEvent $event): void
    {
        $payload = $event->getPayload();
        $token = $event->getToken();

        $token->setAttribute('scope', is_string($payload['scope'] ?? null) ? $payload['scope'] : null);
        $token->setAttribute('mfa', true === ($payload['mfa'] ?? null));
    }
}
