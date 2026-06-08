<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\AdminAccess;
use App\Service\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Revoke operator admin: remove ROLE_ADMIN and clear the TOTP secret. If the
 * email is also present in ADMIN_ALLOWLIST it stays an admin via env — the
 * command warns about that so the operator knows to update the env too.
 */
#[AsCommand(
    name: 'app:admin:revoke',
    description: 'Revoke operator admin (ROLE_ADMIN) from a user and clear their 2FA secret.',
)]
final class AdminRevokeCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AdminAccess $access,
        private readonly AdminAuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the account to demote');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));

        $user = $this->users->findOneBy(['email' => $email]);
        if (null === $user) {
            $io->error(sprintf('No account found for "%s".', $email));

            return Command::FAILURE;
        }

        $roles = array_values(array_filter($user->getRoles(), static fn (string $r): bool => 'ROLE_ADMIN' !== $r));
        $user->setRoles($roles);
        $user->setTotpSecret(null);
        $this->em->flush();

        $this->audit->log('admin.revoked', $email, true, null);

        $io->success(sprintf('Revoked operator admin from %s.', $email));

        if (in_array(strtolower($email), $this->access->emailAllowlist(), true)) {
            $io->warning('This email is still in ADMIN_ALLOWLIST and therefore remains an admin via env. Remove it there too.');
        }

        return Command::SUCCESS;
    }
}
