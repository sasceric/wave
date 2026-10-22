<?php

namespace App\Search;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Private, server-side client. Never forwards provider errors or keys to API responses. */
final class Meilisearch
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(bool:MEILISEARCH_ENABLED)%')] public readonly bool $enabled,
        #[Autowire('%env(string:MEILISEARCH_HOST)%')] private readonly string $host,
        #[Autowire('%env(string:MEILISEARCH_ADMIN_KEY)%')] private readonly string $adminKey,
        #[Autowire('%env(string:MEILISEARCH_SEARCH_KEY)%')] private readonly string $searchKey,
        #[Autowire('%env(string:MEILISEARCH_INDEX_PREFIX)%')] private readonly string $prefix,
    ) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $prefix)) throw new \InvalidArgumentException('Invalid search index prefix.');
    }

    public function index(): string { return $this->prefix.'_search'; }

    public function request(string $method, string $path, ?array $body = null, bool $search = false): array
    {
        if (!$this->enabled) throw new \RuntimeException('Search is not enabled.');
        $key = $search ? $this->searchKey : $this->adminKey;
        $options = ['timeout' => 5, 'max_duration' => 10, 'max_redirects' => 0];
        if ($key !== '') $options['auth_bearer'] = $key;
        if ($body !== null) $options['json'] = $body;
        try {
            return $this->http->request($method, rtrim($this->host, '/').$path, $options)->toArray();
        } catch (\Throwable) {
            throw new \RuntimeException('Search provider request failed.');
        }
    }

    public function wait(array $task): void
    {
        if (!isset($task['taskUid'])) throw new \RuntimeException('Search task acknowledgement missing.');
        $until = microtime(true) + 45;
        do {
            $status = $this->request('GET', '/tasks/'.(int) $task['taskUid']);
            if (($status['status'] ?? '') === 'succeeded') return;
            if (in_array($status['status'] ?? '', ['failed', 'canceled'], true)) throw new \RuntimeException('Search indexing task failed.');
            usleep(100_000);
        } while (microtime(true) < $until);
        throw new \RuntimeException('Search indexing task timed out.');
    }

    public function configure(): void
    {
        // Updating settings creates a missing index; the first document batch supplies its primary key.
        $this->wait($this->request('PATCH', '/indexes/'.$this->index().'/settings', [
            'searchableAttributes' => ['title', 'categories', 'subtitle', 'location', 'text'],
            'displayedAttributes' => ['id', 'entityKey', 'kind', 'slug', 'title', 'subtitle', 'summary', 'location', 'image', 'imageUrl', 'isPresentationDemo', 'compensationType', 'budgetMin', 'budgetMax', 'currency'],
            'filterableAttributes' => ['locale', 'kind', 'ownerId', 'entityKey', 'expiresAt', 'entityId'],
            'sortableAttributes' => ['entityId'],
            'pagination' => ['maxTotalHits' => 1_000_000_000],
            'typoTolerance' => ['enabled' => true],
        ]));
    }
}
