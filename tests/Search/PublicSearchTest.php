<?php

namespace App\Tests\Search;

use App\Search\{Meilisearch, PublicSearch, DatabaseSearch, SearchDocuments};
use App\Safety\BlockPolicy;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PublicSearchTest extends TestCase
{
    public function testShortQueriesNeverReachEitherBackend(): void
    {
        foreach ([false, true] as $enabled) {
            $db = $this->createMock(Connection::class);
            $db->expects(self::never())->method('fetchFirstColumn');
            $db->expects(self::never())->method('fetchAllAssociative');
            $security = $this->createStub(Security::class);
            $http = new MockHttpClient(function () { self::fail('Short queries must not contact Meilisearch.'); });
            $blocks = new BlockPolicy($db, $security);
            $documents = new SearchDocuments($this->createStub(\Doctrine\ORM\EntityManagerInterface::class), new \App\Localization\LocalizedRouteMap(dirname(__DIR__, 2)));
            $search = new PublicSearch(new Meilisearch($http, $enabled, 'http://127.0.0.1:7700', '', '', 'wave_test'), $db, $blocks, new DatabaseSearch($db, $documents, $blocks));
            foreach (['', 'a', ' ab ', 'šđ', '😀😀'] as $term) {
                self::assertSame([], $search->search($term, 'bs')['data']);
                $suggestions = $search->search($term, 'bs', suggest: true);
                self::assertSame(array_fill_keys(PublicSearch::TYPES, []), $suggestions['data']);
                self::assertSame(0, $suggestions['meta']['total']);
            }
        }
    }

    public function testModalUsesExactTotalsAndOnlyTenHitsWithSafetyFilters(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchFirstColumn')->with('SELECT id FROM search_index_change LIMIT 5001')->willReturn(['creator_7']);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('http://127.0.0.1:7700/multi-search', $url);
            self::assertSame('POST', $method);
            self::assertContains('Authorization: Bearer search-only', $options['headers']);
            $queries = json_decode($options['body'], true)['queries'];
            self::assertCount(3, $queries);
            self::assertSame(10, $queries[0]['hitsPerPage']);
            self::assertSame(1, $queries[0]['page']);
            self::assertContains('entityKey NOT IN ["creator_7"]', $queries[0]['filter']);
            self::assertContains('locale = "bs"', $queries[0]['filter']);
            $results = [];
            foreach ([50, 12, 0] as $count) $results[] = ['hits' => array_fill(0, min(10, $count), ['id' => 'example']), 'totalHits' => $count];
            return new MockResponse(json_encode(['results' => $results]));
        });
        $search = new PublicSearch(new Meilisearch($http, true, 'http://127.0.0.1:7700', 'admin-secret', 'search-only', 'wave_test'), $db, new BlockPolicy($db, $security), new DatabaseSearch($db, new SearchDocuments($this->createStub(\Doctrine\ORM\EntityManagerInterface::class), new \App\Localization\LocalizedRouteMap(dirname(__DIR__, 2))), new BlockPolicy($db, $security)));
        $response = $search->search('test', 'bs', suggest: true);
        self::assertCount(10, $response['data']['creators']);
        self::assertSame(['creators' => 50, 'campaigns' => 12, 'companies' => 0], $response['meta']['counts']);
        self::assertSame(62, $response['meta']['total']);
    }

    public function testPageUsesTwentyFiveAndRetainsAllCategoryCounts(): void
    {
        $db = $this->createStub(Connection::class); $db->method('fetchFirstColumn')->willReturn([]);
        $security = $this->createStub(Security::class); $security->method('getUser')->willReturn(null);
        $http = new MockHttpClient(function ($method, $url, $options) {
            $queries = json_decode($options['body'], true)['queries'];
            self::assertCount(4, $queries);
            self::assertSame('UGC', $queries[3]['q']);
            self::assertSame(0, $queries[0]['hitsPerPage']);
            self::assertSame(25, $queries[3]['hitsPerPage']);
            self::assertSame(2, $queries[3]['page']);
            self::assertContains('kind = "creators"', $queries[3]['filter']);
            return new MockResponse(json_encode(['results' => [['hits' => [], 'totalHits' => 50], ['hits' => [], 'totalHits' => 12], ['hits' => [], 'totalHits' => 3], ['hits' => [['id' => 'second-page']], 'totalHits' => 50]]]));
        });
        $search = new PublicSearch(new Meilisearch($http, true, 'http://127.0.0.1:7700', '', '', 'wave_test'), $db, new BlockPolicy($db, $security), new DatabaseSearch($db, new SearchDocuments($this->createStub(\Doctrine\ORM\EntityManagerInterface::class), new \App\Localization\LocalizedRouteMap(dirname(__DIR__, 2))), new BlockPolicy($db, $security)));
        $result = $search->search('UGC', 'bs', 'creators', 2);
        self::assertSame(50, $result['meta']['total']);
        self::assertSame(25, $result['meta']['pageSize']);
        self::assertSame(3, $result['meta']['counts']['companies']);
    }

    public function testProviderErrorsNeverExposeCredentialsOrFallbackToDatabase(): void
    {
        $http = new MockHttpClient(new MockResponse('{"message":"admin-secret"}', ['http_code' => 401]));
        $meili = new Meilisearch($http, true, 'http://127.0.0.1:7700', 'admin-secret', 'search-only', 'wave_test');
        $this->expectExceptionMessage('Search provider request failed.');
        $meili->request('POST', '/multi-search', [], true);
    }
}
