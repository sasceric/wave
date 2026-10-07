<?php

namespace App\Tests\Migration;

use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CompanyProfileMigrationTest extends KernelTestCase
{
    public function testExistingIndustriesAndCitiesSurviveTheMigrations(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $owner = new User('migration-city@example.test', 'ROLE_CREATOR');
        $owner->setPassword('unused');
        $owner->setCity('Zenica');
        $creator = new Creator('migration-owned', 'Owned Creator', 'Food', 'Legacy location', '', []);
        $owner->setCreator($creator);
        $standalone = new Creator('migration-standalone', 'Standalone Creator', 'Food', 'Mostar', '', []);
        $company = new Company('migration-company', 'Company', 'Food');
        foreach ([$owner, $creator, $standalone, $company] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $db = $em->getConnection();
        // Restore the old schema to exercise the actual deployment migrations.
        $db->executeStatement('DROP TABLE company_industry');
        $db->executeStatement('ALTER TABLE company DROP COLUMN cover_media_id');
        $db->executeStatement("ALTER TABLE creator ADD COLUMN location VARCHAR(120) NOT NULL DEFAULT ''");
        $db->executeStatement("UPDATE creator SET location = CASE WHEN owner_id IS NULL THEN city ELSE 'Old duplicate' END");
        $db->executeStatement('ALTER TABLE creator DROP COLUMN city');
        require_once dirname(__DIR__, 2).'/migrations/Version20261007013000.php';
        require_once dirname(__DIR__, 2).'/migrations/Version20261007014000.php';
        $this->execute($db, new \DoctrineMigrations\Version20261007013000($db, new NullLogger()));
        $this->execute($db, new \DoctrineMigrations\Version20261007014000($db, new NullLogger()));
        self::assertSame('Zenica', $db->fetchOne('SELECT city FROM wave_user WHERE id = ?', [$owner->getId()]));
        self::assertSame('Mostar', $db->fetchOne('SELECT city FROM creator WHERE id = ?', [$standalone->getId()]));
        self::assertNull($db->fetchOne('SELECT city FROM creator WHERE id = ?', [$creator->getId()]));
        self::assertSame(['Food'], $db->fetchFirstColumn('SELECT value FROM company_industry WHERE company_id = ?', [$company->getId()]));
        self::assertArrayNotHasKey('location', $db->createSchemaManager()->listTableColumns('creator'));
        self::assertArrayHasKey('cover_media_id', $db->createSchemaManager()->listTableColumns('company'));
        $em->clear();
        self::assertSame('Zenica', $em->find(Creator::class, $creator->getId())->getCity());
        self::assertSame(['Food'], $em->find(Company::class, $company->getId())->getIndustries());
    }

    protected function tearDown(): void
    {
        // Migration-generated constraint names differ from SchemaTool fixture names.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->dropDatabase();
        parent::tearDown();
    }

    private function execute(Connection $connection, AbstractMigration $migration): void
    {
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }
}
