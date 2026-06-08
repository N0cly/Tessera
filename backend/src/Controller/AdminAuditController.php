<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminAuditLog;
use App\Repository\AdminAuditLogRepository;
use App\Service\AdminAuditLogger;
use App\Service\AdminContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read the admin security audit trail (admin logins + customer-data accesses)
 * from within the panel. Admin-only via AdminContext.
 *
 * Reading the trail surfaces accumulated security-sensitive data (operator
 * emails/IPs, attempted-login emails), so the read is itself recorded — there
 * should be no unaudited access to the security log. To avoid self-noise, those
 * AUDIT_VIEW rows are excluded from the returned feed (they remain in the DB).
 */
final class AdminAuditController
{
    public function __construct(
        private readonly AdminContext $admin,
        private readonly AdminAuditLogRepository $logs,
        private readonly AdminAuditLogger $audit,
    ) {
    }

    #[Route('/admin/audit', name: 'admin_audit', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $operator = $this->admin->requireAdmin();

        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = max(1, min(100, (int) $request->query->get('perPage', '50')));
        $offset = ($page - 1) * $perPage;

        $entries = array_map(
            static fn (AdminAuditLog $e): array => [
                'at' => $e->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'actorEmail' => $e->getActorEmail(),
                'action' => $e->getAction(),
                'success' => $e->isSuccess(),
                'ip' => $e->getIp(),
                'detail' => $e->getDetail(),
            ],
            $this->logs->recent($perPage, $offset),
        );
        $total = $this->logs->total();

        // The read itself is an access to the security trail — record it (after
        // building the response, so it doesn't appear in its own page).
        $this->audit->log(
            AdminAuditLogger::AUDIT_VIEW,
            $operator->getEmail(),
            true,
            $this->admin->clientIp(),
            ['page' => $page, 'perPage' => $perPage],
        );

        return new JsonResponse([
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ], headers: ['Cache-Control' => 'private, no-store']);
    }
}
