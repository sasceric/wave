<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlainProfileNameMigrationTest extends KernelTestCase
{
    public function testExistingNamesAreNormalizedWithoutChangingSlugsAndRerunsDoNothing(): void
    {
        self::bootKernel(); $db = static::getContainer()->get('doctrine')->getConnection();
        require_once dirname(__DIR__, 2).'/migrations/Version20261030150000.php';
        $db->beginTransaction();
        try {
            $db->executeStatement('CREATE SCHEMA plain_profile_name_test');
            $db->executeStatement('SET LOCAL search_path TO plain_profile_name_test');
            $db->executeStatement('CREATE TABLE creator (id INT PRIMARY KEY, display_name VARCHAR(120), slug TEXT)');
            $db->executeStatement('CREATE TABLE company (id INT PRIMARY KEY, name VARCHAR(120), slug TEXT)');
            $db->insert('creator', ['id' => 1, 'display_name' => '𝐀𝐦𝐢𝐥𝐚 Čačić', 'slug' => 'keep-creator-slug']);
            $db->insert('creator', ['id' => 2, 'display_name' => 'Šehić Đurić Željko', 'slug' => 'keep-second-slug']);
            $db->insert('company', ['id' => 1, 'name' => '𝐒𝐭𝐮𝐝𝐢𝐨', 'slug' => 'keep-company-slug']);
            // Match the disconnected planning harness: creator exists in the schema,
            // but its name column and the company table have not been applied yet.
            $planningSchema = new Schema();
            $planningSchema->createTable('creator')->addColumn('id', 'integer');
            $planning = new \DoctrineMigrations\Version20261030150000($db, new NullLogger());
            $planning->up($planningSchema);
            self::assertSame([], $planning->getSql());
            self::assertSame('𝐀𝐦𝐢𝐥𝐚 Čačić', $db->fetchOne('SELECT display_name FROM creator WHERE id = 1'));

            // Simulate an installation where the original migration is already recorded.
            require_once dirname(__DIR__, 2).'/migrations/Version20261030160000.php';
            $configuration = new Configuration();
            $configuration->addMigrationClass(\DoctrineMigrations\Version20261030150000::class);
            $configuration->addMigrationClass(\DoctrineMigrations\Version20261030160000::class);
            $factory = DependencyFactory::fromConnection(
                new ExistingConfiguration($configuration),
                new ExistingConnection($db),
                new NullLogger(),
            );
            $metadata = $factory->getMetadataStorage();
            $metadata->ensureInitialized();
            $originalVersion = new Version(\DoctrineMigrations\Version20261030150000::class);
            $repairVersion = new Version(\DoctrineMigrations\Version20261030160000::class);
            $metadata->complete(new ExecutionResult($originalVersion, executedAt: new \DateTimeImmutable()));
            $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($repairVersion);
            self::assertCount(1, $plan);
            self::assertTrue($plan->getFirst()->getVersion()->equals($repairVersion));

            // Dry-run planning must not mutate profile names or record the repair.
            $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setDryRun(true));
            self::assertSame('𝐀𝐦𝐢𝐥𝐚 Čačić', $db->fetchOne('SELECT display_name FROM creator WHERE id = 1'));
            self::assertFalse($metadata->getExecutedMigrations()->hasMigration($repairVersion));

            // A real deploy is a fresh command with a new plan after the dry run.
            $factory = DependencyFactory::fromConnection(
                new ExistingConfiguration($configuration),
                new ExistingConnection($db),
                new NullLogger(),
            );
            $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($repairVersion);
            $factory->getMigrator()->migrate($plan, new MigratorConfiguration());
            self::assertTrue($metadata->getExecutedMigrations()->hasMigration($originalVersion));
            self::assertTrue($metadata->getExecutedMigrations()->hasMigration($repairVersion));
            self::assertCount(0, $factory->getMigrationPlanCalculator()->getPlanUntilVersion($repairVersion));

            // Both migration versions remain idempotent after the repair.
            $schema = $db->createSchemaManager()->introspectSchema();
            $migration = new \DoctrineMigrations\Version20261030150000($db, new NullLogger()); $migration->up($schema);
            self::assertSame([], $migration->getSql());
            self::assertSame('Amila Čačić', $db->fetchOne('SELECT display_name FROM creator WHERE id = 1'));
            self::assertSame('Šehić Đurić Željko', $db->fetchOne('SELECT display_name FROM creator WHERE id = 2'));
            self::assertSame('Studio', $db->fetchOne('SELECT name FROM company WHERE id = 1'));
            self::assertSame('keep-creator-slug', $db->fetchOne('SELECT slug FROM creator WHERE id = 1'));
            self::assertSame('keep-company-slug', $db->fetchOne('SELECT slug FROM company WHERE id = 1'));
            $again = new \DoctrineMigrations\Version20261030160000($db, new NullLogger()); $again->up($schema); self::assertSame([], $again->getSql());
        } finally { $db->rollBack(); }
    }
}
