<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DemoShowcaseLinks;
use App\Service\FeatureFlags;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Create/repair the permanent showcase QR links (one per demo allowlist host).
 * Idempotent; run at backend boot by the entrypoint. No-op outside DEMO_MODE.
 */
#[AsCommand(
    name: 'app:demo:showcase-links',
    description: 'Create or repair the permanent demo showcase links (one per DEMO_REDIRECT_ALLOWLIST host).',
)]
final class DemoShowcaseLinksCommand extends Command
{
    public function __construct(
        private readonly DemoShowcaseLinks $showcase,
        private readonly FeatureFlags $flags,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->flags->isDemoMode()) {
            $io->note('DEMO_MODE is off — nothing to do.');

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn (array $r): array => [$r['host'], $r['url'], $r['status']],
            $this->showcase->ensure(),
        );
        $io->table(['Destination host', 'Short URL (encode this in the QR)', 'Status'], $rows);

        return Command::SUCCESS;
    }
}
