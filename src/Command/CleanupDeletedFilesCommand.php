<?php

namespace App\Command;

use App\Service\StoredFileCleanup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:media:cleanup-deleted', description: 'Retry pending uploaded-file deletion after account or media removal.')]
final class CleanupDeletedFilesCommand extends Command
{
    public function __construct(private readonly StoredFileCleanup $cleanup)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Removed %d pending stored files/directories.', $this->cleanup->run(10000)));

        return Command::SUCCESS;
    }
}
