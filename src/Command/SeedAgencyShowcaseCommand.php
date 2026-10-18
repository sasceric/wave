<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\PresentationDemoSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-agency-showcase', description: 'Upsert the clearly marked Studio Luma agency prototype and its fictional collaboration history.')]
final class SeedAgencyShowcaseCommand extends Command
{
    public function __construct(private readonly PresentationDemoSeeder $seeder) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check identities and assets without changing data.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $accounts = $this->seeder->preflightAgencyShowcase();
            if ($input->getOption('dry-run')) {
                $io->table(['Identity', 'Name', 'Kind'], array_map(static fn (array $row): array => [$row['key'], $row['name'], $row['type']], $accounts));
                $io->success('Preflight passed. No rows or media files changed.');
                return Command::SUCCESS;
            }
            $password = getenv('WAVE_PRESENTATION_PASSWORD');
            $result = $this->seeder->seedAgencyShowcase(is_string($password) && $password !== '' ? $password : null);
            $io->table(['Public path', 'Hidden', 'Services', 'Works', 'Packages'], [[$result['path'], $result['hidden'] ? 'yes' : 'no', $result['services'], $result['portfolio'], $result['packages']]]);
            $io->success('Studio Luma imported. Existing passwords, visibility and URLs preserved. No emails or notifications sent.');
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error($error->getMessage());
            return Command::FAILURE;
        }
    }
}
