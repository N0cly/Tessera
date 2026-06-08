<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Emails of accounts holding ROLE_ADMIN, with whether 2FA is enrolled.
     * Read without hydration (admin listing is a rare CLI op). The roles column
     * is JSON text like ["ROLE_ADMIN"]; the quoted LIKE avoids prefix clashes.
     *
     * @return list<array{email: string, has_2fa: bool}>
     */
    public function adminEmails(): array
    {
        /** @var list<array{email: string, has_2fa: bool}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT email,
                   (totp_secret IS NOT NULL AND totp_secret <> '') AS has_2fa
            FROM users
            WHERE roles::text LIKE '%"ROLE_ADMIN"%'
            ORDER BY email
            SQL,
        );

        return array_map(
            static fn (array $r): array => [
                'email' => (string) $r['email'],
                'has_2fa' => (bool) $r['has_2fa'],
            ],
            $rows,
        );
    }
}
