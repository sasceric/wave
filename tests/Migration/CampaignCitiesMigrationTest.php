<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CampaignCitiesMigrationTest extends KernelTestCase
{
    #[DataProvider('upgradePaths')]
    public function testExistingLocationsBecomeJsonListsWithoutChangingCampaigns(string $type, bool $originalAlreadyApplied): void
    {
        self::bootKernel();
        $db = self::getContainer()->get('doctrine')->getConnection();
        require_once dirname(__DIR__, 2).'/migrations/Version20261103140000.php';
        require_once dirname(__DIR__, 2).'/migrations/Version20261103150000.php';
        $db->beginTransaction();
        try {
            $db->executeStatement('CREATE SCHEMA campaign_cities_migration_test');
            $db->executeStatement('SET LOCAL search_path TO campaign_cities_migration_test');
            $db->executeStatement("CREATE TABLE campaign (id INT PRIMARY KEY, slug TEXT NOT NULL, city $type NOT NULL DEFAULT '')");
            $places = ['Sarajevo', ' Sarajevo, Mostar ,, Zenica ', '', ' Svi gradovi ', 'BiH', 'Čačak, Živinice', 'L\'Aquila', 'Sao "Paulo"', "\t\n"];
            foreach ($places as $id => $place) $db->insert('campaign', ['id' => $id, 'slug' => 'keep-'.$id, 'city' => $place]);
            $classes = $originalAlreadyApplied
                ? [\DoctrineMigrations\Version20261103150000::class]
                : [\DoctrineMigrations\Version20261103140000::class, \DoctrineMigrations\Version20261103150000::class];
            foreach ($classes as $class) {
                $migration = new $class($db, new NullLogger());
                $migration->up(new Schema());
                foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
            }
            self::assertSame('json', $db->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'campaign' AND column_name = 'city'"));
            $expected = [['Sarajevo'], ['Sarajevo', 'Mostar', 'Zenica'], [], [], [], ['Čačak', 'Živinice'], ["L'Aquila"], ['Sao "Paulo"'], []];
            $rows = $db->fetchAllAssociative('SELECT * FROM campaign ORDER BY id');
            foreach ($rows as $id => $row) {
                self::assertSame($expected[$id], json_decode($row['city'], true, flags: JSON_THROW_ON_ERROR));
                self::assertSame('keep-'.$id, $row['slug']);
                self::assertSame($id, $row['id']);
            }
            $db->insert('campaign', ['id' => 20, 'slug' => 'default-all-cities']);
            self::assertSame([], json_decode($db->fetchOne('SELECT city FROM campaign WHERE id = 20'), true, flags: JSON_THROW_ON_ERROR));
            $again = new \DoctrineMigrations\Version20261103150000($db, new NullLogger());
            $again->up(new Schema());
            foreach ($again->getSql() as $query) $db->executeStatement($query->getStatement());
            self::assertSame($rows, $db->fetchAllAssociative('SELECT * FROM campaign WHERE id < 20 ORDER BY id'), 'Re-running must not double-encode JSON or change locations.');
        } finally {
            $db->rollBack();
        }
    }

    public static function upgradePaths(): iterable
    {
        yield 'original VARCHAR single city' => ['VARCHAR(120)', false];
        yield 'already applied TEXT migration' => ['TEXT', true];
    }
}
