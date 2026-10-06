<?php

namespace App\Command;

use App\Background\TaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:scheduled-tasks', description: 'Register missing scheduled tasks or dispatch one bounded batch of due tasks.')]
final class ScheduledTasksCommand extends Command
{
    public function __construct(private readonly TaskRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'register or dispatch');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = match ($input->getArgument('action')) {
            'register' => $this->registry->register(),
            'dispatch' => $this->registry->dispatchDue(),
            default => null,
        };
        if ($count === null) {
            $output->writeln('<error>Use register or dispatch.</error>');

            return Command::INVALID;
        }
        $output->writeln(sprintf('%s: %d task(s).', $input->getArgument('action'), $count));

        return Command::SUCCESS;
    }
}
