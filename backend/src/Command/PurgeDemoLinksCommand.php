<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DemoLinkPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:demo:purge',
    description: 'Delete links owned by the demo account that are older than DEMO_LINK_TTL_HOURS.',
)]
final class PurgeDemoLinksCommand extends Command
{
    public function __construct(private readonly DemoLinkPurger $purger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deleted = $this->purger->purge();
        if (0 === $deleted) {
            $io->writeln('<info>No demo links to purge.</info>');
        } else {
            $io->success(sprintf('Deleted %d demo link%s.', $deleted, 1 === $deleted ? '' : 's'));
        }

        return Command::SUCCESS;
    }
}
