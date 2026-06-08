<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminBillingMetrics;
use App\Service\AdminAuditLogger;
use App\Service\AdminContext;
use App\Service\AdminStats;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Operator panel overview — platform-wide and AGGREGATE-ONLY (no PII). Business
 * KPIs come from Paddle (cached, fail-safe); usage + customer shape from our DB.
 * Authorization (admin role + 2FA + IP) is enforced server-side via
 * AdminContext before any data is read (CLAUDE.md rule 17).
 */
final class AdminOverviewController
{
    private const PERIODS = ['7d' => 7, '30d' => 30, '90d' => 90];
    private const DEFAULT_PERIOD = '30d';

    public function __construct(
        private readonly AdminContext $admin,
        private readonly AdminAuditLogger $audit,
        private readonly AdminBillingMetrics $billing,
        private readonly AdminStats $stats,
    ) {
    }

    #[Route('/admin/overview', name: 'admin_overview', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $operator = $this->admin->requireAdmin();

        $periodKey = (string) $request->query->get('period', self::DEFAULT_PERIOD);
        if (!isset(self::PERIODS[$periodKey])) {
            throw new BadRequestHttpException(
                sprintf('period must be one of %s.', implode(', ', array_keys(self::PERIODS))),
            );
        }
        $days = self::PERIODS[$periodKey];

        $this->audit->log(
            AdminAuditLogger::OVERVIEW_VIEW,
            $operator->getEmail(),
            true,
            $this->admin->clientIp(),
            ['period' => $periodKey],
        );

        $end = (new \DateTimeImmutable('today'))->modify('+1 day');
        $start = $end->modify(sprintf('-%d days', $days));

        return new JsonResponse([
            'period' => $periodKey,
            'business' => $this->billing->business(),
            'usage' => [
                'totalLinks' => $this->stats->totalLinks(),
                'totalScans' => $this->stats->totalScans(),
                'periodScans' => $this->stats->scansBetween($start, $end),
                'activeCodes' => $this->stats->activeCodes($start),
                'scansOverTime' => $this->stats->scansPerDay($start),
            ],
            'customers' => [
                'total' => $this->stats->totalUsers(),
                'churned' => $this->stats->churnedCount(),
                'byPlan' => $this->stats->usersByPlan(),
                'byStatus' => $this->stats->usersByStatus(),
                'signupsOverTime' => $this->stats->signupsPerDay($start),
            ],
        ], headers: ['Cache-Control' => 'private, no-store']);
    }
}
