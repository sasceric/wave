<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\PresentationDemoSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-presentation-demo', description: 'Upsert the three clearly marked fictional showcase accounts, owned images, campaigns and reviews.')]
final class SeedPresentationDemoCommand extends Command
{
    public function __construct(private readonly PresentationDemoSeeder $seeder) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate account identities and assets without writing anything.')
            ->addOption('reset-passwords', null, InputOption::VALUE_NONE, 'Reset existing showcase passwords; hidden profiles remain hidden.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->seeder->preflight();
            if ($input->getOption('dry-run')) {
                $io->table(['Key', 'Email', 'Name'], array_map(static fn ($key, $row) => [$key, $row[0], $row[1]], array_keys(PresentationDemoSeeder::ACCOUNTS), array_values(PresentationDemoSeeder::ACCOUNTS)));
                $io->success('Preflight passed. No database rows or files were changed.');
                return Command::SUCCESS;
            }
            $password = getenv('WAVE_PRESENTATION_PASSWORD');
            if (!is_string($password) || $password === '') {
                if (!$input->isInteractive()) throw new \RuntimeException('Provide WAVE_PRESENTATION_PASSWORD for a non-interactive run.');
                $password = $io->askHidden('Password for the three showcase accounts');
            }
            if (!is_string($password)) throw new \RuntimeException('A password is required.');
            $result = $this->seeder->seed($password, (bool) $input->getOption('reset-passwords'));
            $io->table(['Email', 'Public path', 'Hidden'], array_map(static fn ($row) => [$row['email'], $row['path'], $row['hidden'] ? 'yes' : 'no'], array_values($result)));
            $io->success('Showcase profiles upserted. Existing visibility and passwords were preserved unless explicitly requested. No emails or notifications were sent.');
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error($error->getMessage());
            return Command::FAILURE;
        }
    }
}
