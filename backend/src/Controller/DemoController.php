<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DemoSessionRepository;
use App\Service\DemoSessionManager;
use App\Service\FeatureFlags;
use App\Service\LocaleResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo session entry point (tessera-demo-mode.md). Public — no signup. Creates
 * an anonymous, seeded, ephemeral workspace and returns a token (a JWT for the
 * session's synthetic user) the client uses for the rest of the demo.
 *
 * Abuse guardrails: only available when DEMO_MODE is on, per-IP rate limit on
 * creation, and a hard cap on concurrent sessions.
 */
final class DemoController
{
    public function __construct(
        private readonly FeatureFlags $flags,
        private readonly DemoSessionManager $sessions,
        private readonly DemoSessionRepository $sessionRepo,
        private readonly LocaleResolver $locales,
        #[Autowire(service: 'limiter.demo_session')]
        private readonly RateLimiterFactoryInterface $sessionLimiter,
        #[Autowire('%env(int:DEMO_MAX_SESSIONS)%')]
        private readonly int $maxSessions,
    ) {
    }

    #[Route('/api/demo/session', name: 'demo_session_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->flags->isDemoMode()) {
            // Don't reveal the endpoint on non-demo instances.
            throw new NotFoundHttpException();
        }

        // Per-IP rate limit on session creation (anti session-spam).
        $limit = $this->sessionLimiter->create((string) $request->getClientIp())->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Too many demo sessions from this address.');
        }

        // Hard cap on concurrent sessions (anti mass-spam / resource exhaustion).
        $cap = $this->maxSessions > 0 ? $this->maxSessions : 500;
        if ($this->sessionRepo->countAll() >= $cap) {
            throw new ServiceUnavailableHttpException(message: 'The demo is at capacity right now. Please try again shortly.');
        }

        // Localize the seeded workspace + the synthetic user's locale: prefer an
        // explicit ?locale= query param, else fall back to Accept-Language. Both
        // routes are normalized to a supported locale (default 'en').
        $requested = $request->query->get('locale');
        $locale = (is_string($requested) && '' !== $requested)
            ? $this->locales->normalize($requested)
            : $this->locales->fromAcceptLanguage($request);

        $created = $this->sessions->create($locale);

        return new JsonResponse(
            ['token' => $created['token']],
            201,
            ['Cache-Control' => 'no-store'],
        );
    }
}
