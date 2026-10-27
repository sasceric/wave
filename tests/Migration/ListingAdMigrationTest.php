<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Listing\ListingAds;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261027120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ListingAdMigrationTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $tool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testMigrationCreatesValidInactiveExamplesForEveryDirectory(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            // Work only in the disposable test database; rollback restores its schema.
            $db->executeStatement('DROP TABLE IF EXISTS listing_ad');
            require_once dirname(__DIR__, 2).'/migrations/Version20261027120000.php';
            $migration = new Version20261027120000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            $rows = $db->fetchAllAssociative('SELECT kind, content FROM listing_ad ORDER BY id');
            self::assertSame(ListingAds::KINDS, array_column($rows, 'kind'));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM listing_ad WHERE enabled = TRUE'));
            foreach ($rows as $row) {
                $content = json_decode($row['content'], true, flags: JSON_THROW_ON_ERROR);
                self::assertEquals($content, ListingAds::content($content));
                self::assertCount(2, $content['placements']);
            }
        } finally { $db->rollBack(); }
    }

    public function testCampaignExamplesDeployOnceAndPreserveAdministratorEdits(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $db->executeStatement('DROP TABLE listing_ad');
            require_once dirname(__DIR__, 2).'/migrations/Version20261027120000.php';
            require_once dirname(__DIR__, 2).'/migrations/Version20261027121000.php';
            foreach ([new Version20261027120000($db, new NullLogger()), new \DoctrineMigrations\Version20261027121000($db, new NullLogger())] as $migration) {
                $migration->up(new Schema());
                foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $rows = $db->fetchAllAssociative("SELECT content FROM listing_ad WHERE kind = 'campaigns' ORDER BY id");
            self::assertCount(2, $rows);
            $first = json_decode($rows[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(2, $first['span']);
            self::assertSame(['row' => 1, 'column' => 2], $first['placements'][0]);
            self::assertSame('campaign-invitation', $first['artwork']);
            self::assertSame('/racun/kampanje/nova', $first['url']);
            $ad = new \App\Entity\ListingAd('campaigns'); $ad->setContent($first);
            self::assertSame('/images/listing-ads/create-campaign.svg', ListingAds::resource($ad)['imageUrl']);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM listing_ad WHERE enabled = TRUE'));
            $first['title'] = 'Administrator edit';
            $db->executeStatement("UPDATE listing_ad SET content = ? WHERE kind = 'campaigns' AND content->>'name' = 'Poziv kompanijama'", [json_encode($first, JSON_THROW_ON_ERROR)]);
            $repeat = new \DoctrineMigrations\Version20261027121000($db, new NullLogger());
            $repeat->up(new Schema());
            foreach ($repeat->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            self::assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM listing_ad WHERE kind = 'campaigns'"));
            self::assertSame('Administrator edit', $db->fetchOne("SELECT content->>'title' FROM listing_ad WHERE content->>'name' = 'Poziv kompanijama'"));
        } finally { $db->rollBack(); }
    }

    public function testFinalCampaignCopyAndReminderAreCreatedOnDeploy(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $db->executeStatement('DROP TABLE listing_ad');
            foreach (['20261027120000', '20261027121000', '20261027122000'] as $version) {
                require_once dirname(__DIR__, 2).'/migrations/Version'.$version.'.php';
                $class = 'DoctrineMigrations\\Version'.$version;
                $migration = new $class($db, new NullLogger());
                $migration->up(new Schema());
                foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $rows = $db->fetchAllAssociative("SELECT content FROM listing_ad WHERE kind = 'campaigns' ORDER BY id");
            self::assertCount(3, $rows);
            $content = array_map(static fn (array $row): array => json_decode($row['content'], true, flags: JSON_THROW_ON_ERROR), $rows);
            self::assertSame('Nove kampanje stižu.', $content[0]['title']);
            self::assertSame(2, $content[0]['span']);
            self::assertSame(['row' => 1, 'column' => 2], $content[0]['placements'][0]);
            self::assertSame('/podrska', $content[1]['helpUrl']);
            self::assertSame('/racun/kampanje/nova', $content[1]['url']);
            self::assertSame('Ne propusti sljedeću kampanju.', $content[2]['title']);
            self::assertSame('/racun/postavke', $content[2]['url']);
            self::assertSame(1, $content[2]['span']);
            foreach ($content as $ad) self::assertEquals($ad, ListingAds::content($ad));
            $repeat = new \DoctrineMigrations\Version20261027122000($db, new NullLogger());
            $repeat->up(new Schema());
            foreach ($repeat->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            self::assertSame(3, (int) $db->fetchOne("SELECT COUNT(*) FROM listing_ad WHERE kind = 'campaigns'"));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM listing_ad WHERE enabled = TRUE'));
        } finally { $db->rollBack(); }
    }
}
