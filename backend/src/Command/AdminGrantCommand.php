<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\AdminAuditLogger;
use App\Service\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grant operator admin (ROLE_ADMIN) to an existing account and enrol 2FA.
 *
 * This is the ONLY in-app way to become an admin — there is deliberately no API
 * or signup path (CLAUDE.md rule 17). The account must already exist; it is
 * granted ROLE_ADMIN and issued a TOTP secret, printed once as an otpauth URI
 * for the operator to add to their authenticator app. 2FA is mandatory: an
 * admin with no enrolled secret cannot log in to the panel.
 */
#[AsCommand(
    name: 'app:admin:grant',
    description: 'Grant operator admin (ROLE_ADMIN) to a user and enrol 2FA (TOTP).',
)]
final class AdminGrantCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly TotpService $totp,
        private readonly AdminAuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the (existing) account to promote')
            ->addOption('reset-2fa', null, InputOption::VALUE_NONE, 'Regenerate the TOTP secret even if one exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));

        $user = $this->users->findOneBy(['email' => $email]);
        if (null === $user) {
            $io->error(sprintf('No account found for "%s". The user must register first.', $email));

            return Command::FAILURE;
        }

        $roles = $user->getRoles();
        if (!in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_ADMIN';
            $user->setRoles(array_values(array_unique($roles)));
        }

        $freshSecret = false;
        if (!$user->isTotpEnabled() || $input->getOption('reset-2fa')) {
            $user->setTotpSecret($this->totp->generateSecret());
            $freshSecret = true;
        }

        $this->em->flush();
        $this->audit->log('admin.granted', $email, true, null, ['reset_2fa' => (bool) $input->getOption('reset-2fa')]);

        $io->success(sprintf('%s is now an operator admin.', $email));

        if ($freshSecret) {
            $secret = (string) $user->getTotpSecret();
            $io->section('Enrol two-factor authentication (required to log in)');
            $io->writeln('Add this to your authenticator app (Google Authenticator, Authy, 1Password, …):');
            $io->newLine();
            $io->writeln('  otpauth URI:');
            $io->writeln('  <info>'.$this->totp->provisioningUri($secret, $email).'</info>');
            $io->newLine();
            $io->writeln('  Manual entry secret: <info>'.$secret.'</info>');
            $io->newLine();
            $io->warning('This secret is shown only once. Store it securely and do not commit or share it.');
        } else {
            $io->note('2FA was already enrolled for this account; secret unchanged. Use --reset-2fa to rotate it.');
        }

        return Command::SUCCESS;
    }
}
