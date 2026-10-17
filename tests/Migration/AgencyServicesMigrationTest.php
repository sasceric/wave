<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AgencyServicesMigrationTest extends KernelTestCase
{
    public function testMigrationPreservesLabelsForActiveAndFormerAgencies(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261017210000.php';
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->dropDatabase();
        $db = $em->getConnection();
        $db->executeStatement('CREATE TABLE company (id INT PRIMARY KEY, owner_id INT, company_type VARCHAR(20), name VARCHAR(120))');
        $db->executeStatement('CREATE TABLE creator (id INT PRIMARY KEY, owner_id INT, agency_profile BOOLEAN, tags JSON)');
        $db->executeStatement("INSERT INTO company VALUES (1, 10, 'agency', 'Agency'), (2, 20, 'brand', 'Former agency'), (3, 30, 'brand', 'Brand')");
        $db->executeStatement("INSERT INTO creator VALUES (1, 10, TRUE, ?), (2, 20, TRUE, ?)", ['["Vođenje kampanja","X"]', '["Sačuvana usluga"]']);
        $migration = new \DoctrineMigrations\Version20261017210000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        $services = json_decode($db->fetchOne('SELECT services FROM company WHERE id = 1'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Vođenje kampanja', 'X'], array_column($services, 'title'));
        self::assertSame([], $services[0]['deliverables']);
        self::assertSame('', $services[0]['description']);
        self::assertSame('both', $services[0]['engagement']);
        self::assertSame('Sačuvana usluga', json_decode($db->fetchOne('SELECT services FROM company WHERE id = 2'), true)[0]['title']);
        self::assertSame('[]', $db->fetchOne('SELECT services FROM company WHERE id = 3'));
        self::assertSame('["Vođenje kampanja","X"]', $db->fetchOne('SELECT tags FROM creator WHERE id = 1'));
        self::assertSame('Agency', $db->fetchOne('SELECT name FROM company WHERE id = 1'));
    }
}
