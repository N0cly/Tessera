<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes demo-account links older than DEMO_LINK_TTL_HOURS. Designed for
 * the public demo: if you stand qr-code-redirect up at e.g. demo.example.com
 * and let anyone log in as a shared "demo" user, this prevents the demo
 * from accumulating spam links indefinitely.
 *
 * No-op when DEMO_USER_EMAIL is empty — the policy is opt-in.
 */
final class DemoLinkPurger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::DEMO_USER_EMAIL)%')]
        private readonly ?string $demoEmail,
        #[Autowire('%env(int:DEMO_LINK_TTL_HOURS)%')]
        private readonly int $ttlHours,
    ) {
    }

    /** @return int number of rows deleted */
    public function purge(?\DateTimeImmutable $now = null): int
    {
        $email = is_string($this->demoEmail) ? trim($this->demoEmail) : '';
        if ('' === $email) {
            return 0;
        }

        $hours = $this->ttlHours > 0 ? $this->ttlHours : 24;
        $cutoff = ($now ?? new \DateTimeImmutable())->modify(sprintf('-%d hours', $hours));

        // Single statement, no entity hydration. Cascade FK on scans cleans
        // up matching scan rows automatically.
        $deleted = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            DELETE FROM links
            WHERE owner_id IN (SELECT id FROM users WHERE email = :email)
              AND created_at < :cutoff
            SQL,
            [
                'email' => $email,
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            ],
        );

        if ($deleted > 0) {
            $this->logger->info('Purged demo links.', [
                'demo_email' => $email,
                'cutoff' => $cutoff->format(\DateTimeInterface::ATOM),
                'deleted' => $deleted,
            ]);
        }

        return (int) $deleted;
    }
}
