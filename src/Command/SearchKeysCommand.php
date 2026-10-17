<?php

namespace App\Command;

use App\Search\Meilisearch;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:search:keys', description: 'Create Wave-scoped keys and save them to a private dotenv file, never stdout.')]
final class SearchKeysCommand extends Command
{
    public function __construct(private readonly HttpClientInterface $http, private readonly Meilisearch $meili, #[Autowire('%env(string:MEILISEARCH_HOST)%')] private readonly string $host, #[Autowire('%env(string:MEILISEARCH_MASTER_KEY)%')] private readonly string $masterKey) { parent::__construct(); }
    protected function configure(): void { $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'New private dotenv file in an existing writable directory.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getOption('output');
        if (!is_string($path) || $path === '' || file_exists($path) || !is_dir(dirname($path)) || $this->masterKey === '') {
            $output->writeln('<error>Provide MEILISEARCH_MASTER_KEY and --output pointing to a new private file.</error>'); return Command::INVALID;
        }
        $keys = [];
        try {
            foreach (['ADMIN' => ['indexes.create', 'indexes.get', 'settings.get', 'settings.update', 'documents.add', 'documents.get', 'documents.delete', 'search', 'tasks.get'], 'SEARCH' => ['search']] as $name => $actions) {
                $result = $this->http->request('POST', rtrim($this->host, '/').'/keys', ['auth_bearer' => $this->masterKey, 'json' => ['name' => 'Wave '.$name, 'actions' => $actions, 'indexes' => [$this->meili->index()], 'expiresAt' => null], 'max_redirects' => 0, 'timeout' => 10])->toArray();
                if (!is_string($result['key'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $result['key'])) throw new \RuntimeException('Invalid generated key.');
                $keys[] = 'MEILISEARCH_'.$name.'_KEY='.$result['key'];
            }
            $handle = fopen($path, 'x');
            if ($handle === false) throw new \RuntimeException('Cannot create private key file.');
            try {
                if (!chmod($path, 0600)) throw new \RuntimeException('Cannot restrict private key file permissions.');
                $contents = implode("\n", $keys)."\n";
                if (fwrite($handle, $contents) !== strlen($contents)) throw new \RuntimeException('Cannot save private key file.');
            } finally { fclose($handle); }
            $output->writeln('Wave-scoped keys saved to the requested private dotenv file. Merge into .env.local securely, then remove the bootstrap master variable.');
            return Command::SUCCESS;
        } catch (\Throwable) { $output->writeln('<error>Key setup failed. Check the master key, service availability and output path. Provider details are not printed.</error>'); return Command::FAILURE; }
    }
}
