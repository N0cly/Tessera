<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\DemoSessionManager;
use App\Service\FeatureFlags;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Per-request lifecycle for demo sessions (tessera-demo-mode.md):
 *  - touch `lastActivityAt` so the inactivity clock is sliding;
 *  - lazily purge a session that's already idle past the reset window the moment
 *    it's accessed (defensive, on top of the scheduled job), then reject the
 *    request so the client starts a fresh seeded workspace.
 *
 * Runs at priority 5 — after the firewall (8) so the authenticated synthetic
 * user is available, and after the locale subscriber (6).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final class DemoActivitySubscriber
{
    public function __construct(
        private readonly FeatureFlags $flags,
        private readonly Security $security,
        private readonly DemoSessionManager $sessions,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->flags->isDemoMode() || !$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return; // unauthenticated (e.g. /api/demo/session, /api/config)
        }

        $session = $this->sessions->sessionForUser($user);
        if (null === $session) {
            return; // not a demo user
        }

        if ($session->isStale($this->flags->demoResetHours())) {
            // Idle too long → reap now and force a fresh session.
            $this->sessions->purge($session);
            throw new UnauthorizedHttpException('Bearer', 'Demo session expired.');
        }

        $this->sessions->touch($session);
    }
}
