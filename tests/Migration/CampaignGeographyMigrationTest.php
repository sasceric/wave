<?php

namespace App\Tests\Migration;

use App\Entity\Campaign;
use App\Entity\Company;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CampaignGeographyMigrationTest extends KernelTestCase
{
    public function testLegacyCountriesCitiesAndUnrecognizedTextSurvive(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $company = new Company('migration-brand', 'Brand', 'Food');
        $em->persist($company);
        $places = ['country' => 'Croatia', 'city' => 'Zenica', 'combined' => 'Sarajevo, Bosnia and Herzegovina', 'unknown' => 'United States & Canada'];
        foreach ($places as $slug => $place) {
            $em->persist(new Campaign($slug, $slug, 'Summary', 'Brief', 'Food', ['Instagram'], ['1 post'], 100, 200, $place, 1, new \DateTimeImmutable('+7 days'), new \DateTimeImmutable('today'), $company));
        }
        $em->flush();
        $db = $em->getConnection();
        $db->executeStatement('ALTER TABLE campaign RENAME COLUMN city TO location');
        // Recreate the historical free-text storage rather than today's JSON mapping.
        $db->executeStatement('ALTER TABLE campaign ALTER COLUMN location DROP DEFAULT');
        $db->executeStatement('ALTER TABLE campaign ALTER COLUMN location TYPE VARCHAR(120) USING location::text');
        foreach ($places as $slug => $place) $db->update('campaign', ['location' => $place], ['slug' => $slug]);
        $db->executeStatement('ALTER TABLE campaign DROP COLUMN country_code');
        $db->executeStatement('ALTER TABLE campaign DROP COLUMN categories');
        require_once dirname(__DIR__, 2).'/migrations/Version20261007050000.php';
        $migration = new \DoctrineMigrations\Version20261007050000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement());
        }
        $rows = $db->fetchAllAssociative('SELECT slug, city, country_code FROM campaign ORDER BY slug');
        self::assertSame([
            ['slug' => 'city', 'city' => 'Zenica', 'country_code' => null],
            ['slug' => 'combined', 'city' => 'Sarajevo', 'country_code' => 'BA'],
            ['slug' => 'country', 'city' => '', 'country_code' => 'HR'],
            ['slug' => 'unknown', 'city' => 'United States & Canada', 'country_code' => null],
        ], $rows);
        self::assertArrayNotHasKey('location', $db->createSchemaManager()->listTableColumns('campaign'));
        // Bring the historical result forward before hydrating with the current entity.
        require_once dirname(__DIR__, 2).'/migrations/Version20261103140000.php';
        $cities = new \DoctrineMigrations\Version20261103140000($db, new NullLogger());
        $cities->up(new Schema());
        foreach ($cities->getSql() as $query) $db->executeStatement($query->getStatement());
        $em->clear();
        self::assertSame(['Food'], $em->getRepository(Campaign::class)->findOneBy(['slug' => 'country'])->getCategories());
    }

    protected function tearDown(): void
    {
        (new SchemaTool(self::getContainer()->get(EntityManagerInterface::class)))->dropDatabase();
        parent::tearDown();
    }
}
