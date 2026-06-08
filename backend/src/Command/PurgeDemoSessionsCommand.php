<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DemoSessionPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge demo workspaces idle past DEMO_SESSION_TTL_HOURS. Run periodically by
 * the `cron` service; no-op unless DEMO_MODE is on.
 */
#[AsCommand(
    name: 'app:demo:purge-sessions',
    description: 'Delete demo workspaces (session + synthetic user + links/scans) idle past the reset window.',
)]
final class PurgeDemoSessionsCommand extends Command
{
    public function __construct(private readonly DemoSessionPurger $purger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $purged = $this->purger->purge();
        if (0 === $purged) {
            $io->writeln('<info>No stale demo workspaces to purge.</info>');
        } else {
            $io->success(sprintf('Purged %d stale demo workspace%s.', $purged, 1 === $purged ? '' : 's'));
        }

        return Command::SUCCESS;
    }
}
