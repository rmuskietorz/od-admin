<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LoginAttemptRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:audit:purge',
    description: 'Loescht Login-Versuche aelter als N Tage (Default 90)',
)]
final class PurgeAuditCommand extends Command
{
    public function __construct(private readonly LoginAttemptRepository $repo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Aufbewahrungsdauer in Tagen', '90');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $threshold = new \DateTimeImmutable("-{$days} days");

        $count = $this->repo->purgeOlderThan($threshold);
        $io->success("{$count} Eintraege geloescht (aelter als {$days} Tage).");

        return Command::SUCCESS;
    }
}
