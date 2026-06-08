<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\AdminAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List operator admins: those holding ROLE_ADMIN in the database (with whether
 * 2FA is enrolled) plus any granted purely via the ADMIN_ALLOWLIST env var.
 */
#[AsCommand(
    name: 'app:admin:list',
    description: 'List operator admins (DB role + env allowlist) and their 2FA status.',
)]
final class AdminListCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AdminAccess $access,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dbAdmins = $this->users->adminEmails();
        $dbEmails = array_map(static fn (array $a): string => strtolower($a['email']), $dbAdmins);

        $rows = [];
        foreach ($dbAdmins as $admin) {
            $rows[] = [
                $admin['email'],
                'DB role',
                $admin['has_2fa'] ? 'enrolled' : 'NOT enrolled',
            ];
        }
        foreach ($this->access->emailAllowlist() as $email) {
            if (in_array($email, $dbEmails, true)) {
                continue; // already shown as a DB admin
            }
            $user = $this->users->findOneBy(['email' => $email]);
            $rows[] = [
                $email,
                'env allowlist',
                ($user?->isTotpEnabled() ?? false) ? 'enrolled' : 'NOT enrolled',
            ];
        }

        if ([] === $rows) {
            $io->warning('No operator admins configured. Grant one with: bin/console app:admin:grant <email>');

            return Command::SUCCESS;
        }

        $io->table(['Email', 'Source', '2FA'], $rows);
        $io->note('Admins without 2FA enrolled cannot log in. Run app:admin:grant <email> to enrol.');

        return Command::SUCCESS;
    }
}
