<?php

namespace App\Tests\Migration;

use App\Service\MaintenanceMode;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MaintenanceMigrationTest extends KernelTestCase
{
    public function testDeployDefaultsOffAndRollbackCannotSilentlyRemoveAnActiveMode(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $db = $em->getConnection();
        $db->executeStatement('DROP TABLE platform_settings');
        require_once dirname(__DIR__, 2).'/migrations/Version20261027180000.php';
        $migration = new \DoctrineMigrations\Version20261027180000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement());
        $mode = self::getContainer()->get(MaintenanceMode::class);
        self::assertFalse($mode->enabled());
        $mode->setEnabled(true);
        self::assertTrue($mode->enabled());
        $this->expectException(AbortMigration::class);
        (new \DoctrineMigrations\Version20261027180000($db, new NullLogger()))->down(new Schema());
    }
}
