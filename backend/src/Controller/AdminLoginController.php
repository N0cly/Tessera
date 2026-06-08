<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AdminAccess;
use App\Service\AdminAuditLogger;
use App\Service\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Operator admin login with mandatory 2FA (CLAUDE.md rule 17). Requires email +
 * password + a valid TOTP code, and that the account is an admin (DB role or env
 * allowlist) with 2FA enrolled. On success it mints a short, admin-scoped JWT
 * (`scope=admin, mfa=true`) — the ONLY token that can reach admin endpoints.
 *
 * Hardening:
 *  - per-IP rate limiting (brute-force defence);
 *  - one generic 401 for every credential/2FA/role failure + a dummy password
 *    verification on unknown emails, so neither the response nor timing reveals
 *    whether an account exists or is an admin (no user enumeration);
 *  - every attempt (success and failure) is written to the admin audit log;
 *  - optional IP allowlist via AdminAccess.
 */
final class AdminLoginController
{
    private const GENERIC_ERROR = 'Invalid credentials.';
    /** Reject absurdly long emails outright (well above RFC max) before any work. */
    private const MAX_EMAIL_LENGTH = 254;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AdminAccess $access,
        private readonly TotpService $totp,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly AdminAuditLogger $audit,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.admin_login')]
        private readonly RateLimiterFactoryInterface $loginLimiter,
        #[Autowire(service: 'app.cache.admin')]
        private readonly CacheInterface $cache,
    ) {
    }

    #[Route('/admin/login', name: 'admin_login', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $ip = $request->getClientIp();

        // IP allowlist (if configured) — refuse before doing anything else.
        if (!$this->access->isIpAllowed($ip)) {
            return new JsonResponse(['error' => 'Forbidden.'], 403);
        }

        // Per-IP rate limit (anti brute-force).
        $limit = $this->loginLimiter->create((string) $ip)->consume(1);
        if (!$limit->isAccepted()) {
            $this->audit->log(AdminAuditLogger::LOGIN_FAILURE, '(rate-limited)', false, $ip, ['reason' => 'rate_limited']);
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return new JsonResponse(
                ['error' => 'Too many attempts. Please slow down.'],
                429,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $email = is_array($payload) && is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        $password = is_array($payload) && is_string($payload['password'] ?? null) ? $payload['password'] : '';
        $code = is_array($payload) && is_string($payload['code'] ?? null) ? $payload['code'] : '';

        // Cap the email up front: anything beyond the RFC max can't be a real
        // account and must not reach the (length-limited) audit column.
        if (mb_strlen($email) > self::MAX_EMAIL_LENGTH) {
            $email = '(too-long)';
            $user = null;
        } else {
            $user = '' !== $email ? $this->users->findOneBy(['email' => $email]) : null;
        }

        // Always verify a password (real or dummy) so timing doesn't reveal
        // whether the account exists — anti-enumeration.
        $passwordOk = null !== $user
            ? $this->hasher->isPasswordValid($user, $password)
            : $this->burnDummyHash($password);

        $matchedStep = null;
        $reason = $this->failureReason($user, $passwordOk, $code, $matchedStep);

        if (null !== $reason) {
            $this->audit->log(AdminAuditLogger::LOGIN_FAILURE, '' !== $email ? $email : '(blank)', false, $ip, ['reason' => $reason]);

            return new JsonResponse(['error' => self::GENERIC_ERROR], 401);
        }

        /** @var User $user (non-null once reason is null) */
        // Burn the consumed TOTP step so the same code can't be replayed.
        $user->setLastTotpStep($matchedStep);
        $this->em->flush();

        $token = $this->jwt->createFromPayload($user, ['scope' => 'admin', 'mfa' => true]);
        $this->audit->log(AdminAuditLogger::LOGIN_SUCCESS, $user->getEmail(), true, $ip);

        return new JsonResponse(['token' => $token], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Returns an internal failure reason (for the audit log) or null when every
     * factor passed. The reason never reaches the client — the response is
     * always the same generic 401. On success, $matchedStep is set to the TOTP
     * step that matched so the caller can mark it consumed (replay protection).
     */
    private function failureReason(?User $user, bool $passwordOk, string $code, ?int &$matchedStep): ?string
    {
        $matchedStep = null;

        if (null === $user || !$passwordOk) {
            return 'bad_credentials';
        }
        if (!$this->access->isAdmin($user)) {
            return 'not_admin';
        }
        if (!$user->isTotpEnabled()) {
            return 'no_2fa_enrolled';
        }

        $step = $this->totp->matchStep((string) $user->getTotpSecret(), $code);
        if (null === $step) {
            return 'bad_2fa_code';
        }
        // Single-use: a code whose step was already consumed cannot be replayed.
        $last = $user->getLastTotpStep();
        if (null !== $last && $step <= $last) {
            return 'totp_replayed';
        }

        $matchedStep = $step;

        return null;
    }

    /**
     * Verify the password against a stable dummy hash so the unknown-account
     * path costs the same single KDF as the real one (anti-enumeration). The
     * dummy hash is computed once and cached, so this path never runs the
     * (expensive) hashPassword on the request hot path.
     */
    private function burnDummyHash(string $password): bool
    {
        $hash = $this->cache->get('admin.login.dummy_hash', function (ItemInterface $item): string {
            $item->expiresAfter(86400);

            return $this->hasher->hashPassword(
                (new User())->setEmail('nobody@example.invalid'),
                'unused-dummy-password',
            );
        });

        $dummy = (new User())->setEmail('nobody@example.invalid');
        $dummy->setPassword($hash);
        $this->hasher->isPasswordValid($dummy, $password);

        return false;
    }
}
