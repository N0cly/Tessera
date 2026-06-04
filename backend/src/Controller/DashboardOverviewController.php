<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\LinkRepository;
use App\Repository\ScanRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Account-level dashboard overview: aggregates across ALL of the current
 * user's links. Owner-scoped, computed on the fly via SQL GROUP BY — no
 * precomputed roll-up table (v1 scope per CLAUDE.md).
 */
final class DashboardOverviewController
{
    /** Accepted ?period= values mapped to their length in days. */
    private const PERIODS = ['7d' => 7, '30d' => 30, '90d' => 90];
    private const DEFAULT_PERIOD = '30d';

    public function __construct(
        private readonly LinkRepository $links,
        private readonly ScanRepository $scans,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/dashboard/overview', name: 'dashboard_overview', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $periodKey = (string) $request->query->get('period', self::DEFAULT_PERIOD);
        if (!isset(self::PERIODS[$periodKey])) {
            throw new BadRequestHttpException(
                sprintf('period must be one of %s.', implode(', ', array_keys(self::PERIODS))),
            );
        }
        $days = self::PERIODS[$periodKey];

        $ownerId = $user->getId();

        // Full-day, half-open windows so current and previous periods are
        // exactly equal in length. end = start of tomorrow.
        $end = (new \DateTimeImmutable('today'))->modify('+1 day');
        $periodStart = $end->modify(sprintf('-%d days', $days));
        $prevStart = $periodStart->modify(sprintf('-%d days', $days));

        $activeCodes = $this->links->countForOwner($user);
        $totalScans = $this->scans->countTotalForOwner($ownerId);
        $periodScans = $this->scans->countForOwnerBetween($ownerId, $periodStart, $end);
        $prevScans = $this->scans->countForOwnerBetween($ownerId, $prevStart, $periodStart);

        return new JsonResponse([
            'kpis' => [
                'activeCodes' => $activeCodes,
                'totalScans' => $totalScans,
                'periodScans' => $periodScans,
                'periodScansChangePct' => $this->changePct($periodScans, $prevScans),
                'avgScansPerCode' => $activeCodes > 0 ? (int) round($periodScans / $activeCodes) : 0,
            ],
            'timeSeries' => $this->scans->scansPerDayForOwner($ownerId, $periodStart),
            'topLinks' => $this->scans->topLinksForOwner($ownerId, $periodStart),
            'byDevice' => $this->scans->deviceBreakdownForOwner($ownerId, $periodStart),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Percentage change vs the previous equal-length period. With no baseline
     * (no scans last period) we report 0 rather than a misleading ∞/+100%.
     */
    private function changePct(int $current, int $previous): int
    {
        if (0 === $previous) {
            return 0;
        }

        return (int) round(($current - $previous) / $previous * 100);
    }
}
