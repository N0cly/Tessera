<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\LinkRepository;
use App\Repository\ScanRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Owner-scoped link analytics. Aggregations are computed on the fly via SQL
 * GROUP BY — no precomputed roll-up table (v1 scope per CLAUDE.md).
 */
final class LinkStatsController
{
    private const ALLOWED_PERIODS = [7, 30, 90];

    public function __construct(
        private readonly LinkRepository $links,
        private readonly ScanRepository $scans,
        private readonly Security $security,
    ) {
    }

    #[Route(
        '/api/links/{id}/stats',
        name: 'link_stats',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new NotFoundHttpException();
        }

        $link = $this->links->find($id);
        if (null === $link || $link->getOwner() !== $user) {
            // Same response as "not found" — don't leak existence.
            throw new NotFoundHttpException();
        }

        $period = (int) $request->query->get('period', '30');
        if (!\in_array($period, self::ALLOWED_PERIODS, true)) {
            throw new BadRequestHttpException(
                sprintf('period must be one of %s.', implode(', ', self::ALLOWED_PERIODS)),
            );
        }

        $since = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $period - 1));
        $linkId = $link->getId();

        return new JsonResponse([
            'linkId' => (string) $linkId,
            'period' => $period,
            'since' => $since->format('Y-m-d'),
            'total' => $this->scans->countTotal($linkId, $since),
            'perDay' => $this->scans->countPerDay($linkId, $since),
            'byCountry' => $this->scans->countByCountry($linkId, $since),
            'byDevice' => $this->scans->countByDevice($linkId, $since),
            'byOs' => $this->scans->countByOs($linkId, $since),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }
}
