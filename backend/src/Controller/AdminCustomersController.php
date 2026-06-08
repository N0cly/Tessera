<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminAuditLogger;
use App\Service\AdminContext;
use App\Service\AdminStats;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer data for the operator panel — this is the ONLY admin endpoint that
 * exposes PII (customer emails), so per the data-minimization rule it is
 * separate from the aggregate overview and EVERY access is written to the audit
 * log (CLAUDE.md rule 17 / tessera-admin-panel.md). The frontend loads it only
 * when the operator opens the Customers tab.
 *
 * No scanner identity is ever exposed — scans store no raw IP (rule 5).
 */
final class AdminCustomersController
{
    private const PERIODS = ['7d' => 7, '30d' => 30, '90d' => 90];
    private const DEFAULT_PERIOD = '30d';

    public function __construct(
        private readonly AdminContext $admin,
        private readonly AdminAuditLogger $audit,
        private readonly AdminStats $stats,
    ) {
    }

    #[Route('/admin/customers', name: 'admin_customers', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $operator = $this->admin->requireAdmin();

        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = max(1, min(100, (int) $request->query->get('perPage', '25')));
        $offset = ($page - 1) * $perPage;

        $periodKey = (string) $request->query->get('period', self::DEFAULT_PERIOD);
        $days = self::PERIODS[$periodKey] ?? self::PERIODS[self::DEFAULT_PERIOD];
        $since = (new \DateTimeImmutable('today'))->modify('+1 day')->modify(sprintf('-%d days', $days));

        $customers = $this->stats->customerList($perPage, $offset);
        $total = $this->stats->totalUsers();
        $topByUsage = $this->stats->topCustomersByUsage($since);

        // PII access — record it (who, when, from where, how much).
        $this->audit->log(
            AdminAuditLogger::CUSTOMERS_VIEW,
            $operator->getEmail(),
            true,
            $this->admin->clientIp(),
            ['page' => $page, 'perPage' => $perPage, 'returned' => count($customers), 'topByUsage' => count($topByUsage)],
        );

        return new JsonResponse([
            'customers' => $customers,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'topByUsage' => $topByUsage,
        ], headers: ['Cache-Control' => 'private, no-store']);
    }
}
