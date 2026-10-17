<?php

namespace App\Tests\Search;

use App\Background\JobDispatcher;
use App\Entity\{Campaign, Company, Creator, User};
use App\EventSubscriber\SearchIndexSubscriber;
use App\Localization\LocalizedRouteMap;
use App\Safety\BlockPolicy;
use App\Search\{Meilisearch, PublicSearch, SearchDocuments, SearchIndexer, DatabaseSearch};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;

/** Opt-in real engine test; never points at the developer's or Zeka's index. */
final class MeilisearchIntegrationTest extends KernelTestCase
{
    public function testRealEnginePaginationVisibilityAndQueuedInvalidation(): void
    {
        $host = getenv('WAVE_SEARCH_TEST_HOST');
        if (!$host) self::markTestSkipped('Set WAVE_SEARCH_TEST_HOST to an isolated Meilisearch instance.');
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        if (!str_ends_with($db->getDatabase(), '_test') || !$db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) self::fail('Integration tests require an isolated PostgreSQL _test database.');
        $tool = new SchemaTool($em); $metadata = $em->getMetadataFactory()->getAllMetadata(); $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $meili = new Meilisearch(HttpClient::create(), true, $host, '', '', 'wave_test_'.bin2hex(random_bytes(5)));
        $meili->configure();
        try {
            $creators = [];
            for ($i = 0; $i < 51; ++$i) {
                $creator = new Creator('search-creator-'.$i, 'SearchMatch '.$i, 'Beauty', 'Sarajevo', 'Public biography', []);
                $em->persist($creator); $creators[] = $creator;
            }
            $owner = new User('private@example.test', 'ROLE_COMPANY'); $owner->setPassword('unused-test-hash');
            $company = new Company('search-brand', 'SearchMatch Brand', 'Beauty'); $company->setOwner($owner);
            $campaign = new Campaign('search-campaign', 'SearchMatch Campaign', 'SearchMatch '.str_repeat('Summary context ', 10).'summaryneedlequartz', 'SearchMatch details', 'Beauty', [], [], 100, 300, 'Sarajevo', 1, new \DateTimeImmutable('+10 days'), new \DateTimeImmutable(), $company);
            $campaign->setTranslations(['de' => ['summary' => 'zusammenfassungnadelquarz']]);
            foreach ([$owner, $company, $campaign] as $entity) $em->persist($entity);
            $em->flush();
            $creatorIds = array_map(static fn (Creator $row): int => $row->getId(), $creators); $companyId = $company->getId(); $ownerId = $owner->getId(); $campaignId = $campaign->getId();
            $routes = $container->get(LocalizedRouteMap::class);
            $indexer = new SearchIndexer($meili, new SearchDocuments($em, $routes), $db, $em, $container->get(JobDispatcher::class), $routes);
            $indexer->index('creator', $creatorIds); $indexer->index('company', [$companyId]); $indexer->index('campaign', [$campaignId]);
            $search = new PublicSearch($meili, $db, $container->get(BlockPolicy::class), $container->get(DatabaseSearch::class));
            $suggestions = $search->search('SearchMatch', 'bs', suggest: true);
            self::assertSame(51, $suggestions['meta']['counts']['creators']); self::assertCount(10, $suggestions['data']['creators']);
            self::assertSame(53, $suggestions['meta']['total']);
            $summaryMatch = $search->search('summaryneedlequartz', 'bs');
            self::assertSame(1, $summaryMatch['meta']['counts']['campaigns']);
            self::assertSame('search-campaign', $summaryMatch['data'][0]['slug']);
            self::assertSame(1, $search->search('zusammenfassungnadelquarz', 'de')['meta']['counts']['campaigns']);
            self::assertSame(0, $search->search('zusammenfassungnadelquarz', 'bs')['meta']['total']);
            self::assertSame(52, $search->search('Sarajevo', 'bs')['meta']['total']);
            $dbBackend = new DatabaseSearch($db, new SearchDocuments($em, $routes), $container->get(BlockPolicy::class));
            $databaseSearch = new PublicSearch(new Meilisearch(HttpClient::create(), false, $host, '', '', 'wave_test_disabled'), $db, $container->get(BlockPolicy::class), $dbBackend);
            self::assertSame(53, $databaseSearch->search('SearchMatch', 'bs')['meta']['total']);
            self::assertSame(1, $databaseSearch->search('summaryneedlequartz', 'bs')['meta']['counts']['campaigns']);
            self::assertSame(1, $databaseSearch->search('zusammenfassungnadelquarz', 'de')['meta']['counts']['campaigns']);
            self::assertCount(10, $databaseSearch->search('SearchMatch', 'bs', suggest: true)['data']['creators']);
            self::assertCount(25, $databaseSearch->search('SearchMatch', 'bs', 'creators', 2)['data']);
            $viewer = new User('viewer@example.test', 'ROLE_CREATOR'); $viewer->setPassword('unused'); $em->persist($viewer); $em->flush();
            $security = $this->createStub(\Symfony\Bundle\SecurityBundle\Security::class); $security->method('getUser')->willReturn($viewer);
            $em->persist(new \App\Entity\UserBlock(bin2hex(random_bytes(16)), $em->find(User::class, $ownerId), $viewer, new \DateTimeImmutable(), null)); $em->flush();
            $blockedSearch = new PublicSearch($meili, $db, new BlockPolicy($db, $security), $dbBackend);
            self::assertSame(51, $blockedSearch->search('SearchMatch', 'bs')['meta']['total'], 'Reverse personal blocks must filter engine counts and hits.');

            $one = $search->search('SearchMatch', 'bs', 'creators'); $two = $search->search('SearchMatch', 'bs', 'creators', 2); $three = $search->search('SearchMatch', 'bs', 'creators', 3);
            self::assertCount(25, $one['data']); self::assertCount(25, $two['data']); self::assertCount(1, $three['data']);
            self::assertSame([], array_intersect(array_column($one['data'], 'id'), array_column($two['data'], 'id')));
            self::assertSame(53, $search->search('SearchMatch', 'de')['meta']['total']);
            self::assertStringNotContainsString('private@example.test', json_encode($suggestions));
            // Cross the batch boundary without changing the matching result set.
            for ($i = 0; $i < 50; ++$i) $em->persist(new Creator('other-creator-'.$i, 'Unrelated '.$i, 'Technology', 'Berlin', '', []));
            $em->flush();
            $next = $indexer->reconcile(['kind' => 'creator'], queue: false);
            self::assertSame('source', $next['phase']);
            self::assertSame((int) $db->fetchOne('SELECT id FROM creator ORDER BY id LIMIT 1 OFFSET 99'), $next['after']);
            self::assertSame(['kind' => 'creator', 'phase' => 'prune'], $indexer->reconcile($next, queue: false));
            self::assertSame(51, $search->search('SearchMatch', 'bs')['meta']['counts']['creators']);

            // A task accepted over HTTP but failed by the engine must retain its fence.
            $indexer->mark('creator', $creatorIds[0]);
            $failedHttp = new \Symfony\Component\HttpClient\MockHttpClient([
                new \Symfony\Component\HttpClient\Response\MockResponse('{"taskUid":1}'),
                new \Symfony\Component\HttpClient\Response\MockResponse('{"status":"failed"}'),
            ]);
            $failedMeili = new Meilisearch($failedHttp, true, $host, '', '', 'wave_test_failed');
            $failedIndexer = new SearchIndexer($failedMeili, new SearchDocuments($em, $routes), $db, $em, $container->get(JobDispatcher::class), $routes);
            try { $failedIndexer->index('creator', [$creatorIds[0]]); self::fail('A failed engine task must fail indexing.'); }
            catch (\RuntimeException $error) { self::assertSame('Search indexing task failed.', $error->getMessage()); }
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM search_index_change'));
            self::assertSame(50, $search->search('SearchMatch', 'bs')['meta']['counts']['creators']);
            $indexer->index('creator', [$creatorIds[0]]);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM search_index_change'));
            // Hook the real change subscriber for a profile privacy mutation.
            $subscriber = new SearchIndexSubscriber($meili, $indexer, $container->get(JobDispatcher::class));
            $em->getEventManager()->addEventListener(['onFlush', 'postFlush'], $subscriber);
            $owner = $em->find(User::class, $ownerId); $owner->setHideMyAccount(true); $em->flush();
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM search_index_change'));
            self::assertSame(51, $search->search('SearchMatch', 'bs')['meta']['total'], 'Pending hidden company and its campaign must disappear before the worker runs.');
            self::assertGreaterThanOrEqual(1, (int) $db->fetchOne("SELECT COUNT(*) FROM background_job WHERE type = 'SearchIndexBatchMessage'"));
            $indexer->index('company', [$companyId]); $indexer->index('campaign', [$campaignId]);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM search_index_change'));
            self::assertSame(51, $search->search('SearchMatch', 'bs')['meta']['total']);
            $em->getEventManager()->removeEventListener(['onFlush', 'postFlush'], $subscriber);
            // A direct hard deletion is repaired by the keyset sweep, too.
            $db->delete('creator', ['id' => $creatorIds[0]]);
            $payload = ['kind' => 'creator', 'phase' => 'prune'];
            do { $payload = $indexer->reconcile($payload, queue: false); } while ($payload !== null);
            self::assertSame(50, $search->search('SearchMatch', 'bs')['meta']['counts']['creators']);
        } finally {
            $meili->wait($meili->request('DELETE', '/indexes/'.$meili->index()));
        }
    }
}
