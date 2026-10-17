<?php

namespace App\Command;

use App\Background\JobDispatcher;
use App\Search\{Meilisearch, SearchIndexer};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:search:index', description: 'Configure Wave Meilisearch and queue a complete batched reconciliation.')]
final class SearchIndexCommand extends Command
{
    public function __construct(private readonly Meilisearch $meili, private readonly JobDispatcher $jobs, private readonly SearchIndexer $indexer) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('sync', null, InputOption::VALUE_NONE, 'Index existing source rows now in batches of 100 (without a worker).')
            ->addOption('configure-only', null, InputOption::VALUE_NONE, 'Create/update only this Wave index settings.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->meili->enabled) { $output->writeln('<error>Set MEILISEARCH_ENABLED=true and configure the server-side .env variables first.</error>'); return Command::FAILURE; }
        try {
            $this->meili->configure();
            if ($input->getOption('configure-only')) { $output->writeln('Configured '.$this->meili->index()); return Command::SUCCESS; }
            if (!$input->getOption('sync')) {
                $id = $this->jobs->enqueue('SearchReconcileTask');
                $output->writeln('Queued Wave search reconciliation '.$id.'; run the background queue consumer.');
            } else {
                $payload = [];
                do {
                    $payload = $this->indexer->reconcile($payload, queue: false);
                    $output->writeln('Processed one bounded search indexing batch.');
                } while ($payload !== null);
                $output->writeln('Indexing and stale-document sweep complete.');
            }
            return Command::SUCCESS;
        } catch (\Throwable) { $output->writeln('<error>Search indexing failed. Check the service, .env credentials and index permissions; no provider secrets are printed.</error>'); return Command::FAILURE; }
    }
}
