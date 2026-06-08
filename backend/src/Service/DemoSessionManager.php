<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\LinkCache;
use App\Entity\DemoSession;
use App\Entity\User;
use App\Repository\DemoSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * Lifecycle of an ephemeral demo workspace (tessera-demo-mode.md / CLAUDE.md
 * rule 19). Each session is backed by a per-session synthetic User — no PII,
 * no real credentials — that OWNS the session's links/scans, so the platform's
 * existing owner-scoping isolates every session automatically.
 *
 * The client holds a JWT for that synthetic user (carrying a `demo` claim); the
 * whole workspace is purged after inactivity by deleting the user (DB cascades
 * the session, links and scans).
 */
final class DemoSessionManager
{
    /** Long-lived enough that an actively-used demo never expires mid-session; */
    /** idle sessions are reaped earlier by the inactivity purge. */
    private const TOKEN_TTL_SECONDS = 86400;

    /** Skip the activity write unless this much time has passed (write-on-read debounce). */
    private const TOUCH_DEBOUNCE_SECONDS = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemoSessionRepository $sessions,
        private readonly DemoWorkspaceSeeder $seeder,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly LinkCache $linkCache,
    ) {
    }

    /**
     * Create a synthetic user + session, seed its workspace, and return the
     * client token (a JWT for the synthetic user).
     *
     * The seeded link names are localized once, at seed time, to $locale
     * (CLAUDE.md rule 18); $locale also becomes the synthetic user's stored
     * locale so the rest of the demo UI follows the visitor's language. A null /
     * unsupported locale falls back to English.
     *
     * @return array{token: string, session: DemoSession}
     */
    public function create(?string $locale = null): array
    {
        $seedLocale = (null !== $locale && '' !== $locale) ? $locale : 'en';

        $user = new User();
        $user->setEmail(sprintf('demo-%s@demo.invalid', bin2hex(random_bytes(8))));
        // The synthetic user never logs in with a password (JWT only); store an
        // unusable placeholder so the NOT NULL column is satisfied.
        $user->setPassword('!demo-no-login!');
        if (null !== $locale && '' !== $locale) {
            $user->setLocale($locale);
        }
        $this->em->persist($user);

        $session = new DemoSession($user, $this->randomToken());
        $this->em->persist($session);
        $this->em->flush();

        // Seed AFTER the user/session exist so links are owned + counted.
        $this->seeder->seed($user, $seedLocale);

        $token = $this->jwt->createFromPayload($user, [
            'demo' => true,
            'demoSession' => (string) $session->getId(),
            'exp' => time() + self::TOKEN_TTL_SECONDS,
        ]);

        return ['token' => $token, 'session' => $session];
    }

    /** The demo session a (synthetic) user belongs to, or null for a real user. */
    public function sessionForUser(User $user): ?DemoSession
    {
        return $this->sessions->findOneByUser($user);
    }

    public function touch(DemoSession $session): void
    {
        $now = new \DateTimeImmutable();
        // Debounce: a sliding inactivity window only needs ~minute granularity,
        // so don't issue a DB write on every read-only request.
        if ($session->getLastActivityAt() > $now->modify(sprintf('-%d seconds', self::TOUCH_DEBOUNCE_SECONDS))) {
            return;
        }
        $session->touch($now);
        $this->em->flush();
    }

    /**
     * Purge one workspace now (lazy reset on access). Deleting the synthetic
     * user cascades the session, links and scans (FK onDelete CASCADE). We bust
     * the redirect cache for the owner's slugs FIRST (while the links still
     * exist) so a purged code never keeps serving from Redis up to the safety
     * TTL — DB cascades don't fire the Doctrine cache-invalidation listener.
     */
    public function purge(DemoSession $session): void
    {
        $this->linkCache->invalidateForOwner($session->getUser());
        $this->em->remove($session->getUser());
        $this->em->flush();
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
