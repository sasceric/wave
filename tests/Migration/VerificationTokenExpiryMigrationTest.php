<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VerificationTokenExpiryMigrationTest extends KernelTestCase
{
    public function testRetainsOriginalVerificationLinksAndPasswordExpiry(): void
    {
        self::bootKernel();
        $configured = self::getContainer()->get('doctrine')->getConnection();
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connections = [$sqlite];
        if ($configured->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $connections[] = $configured;
        }
        require_once dirname(__DIR__, 2).'/migrations/Version20261022140000.php';
        foreach ($connections as $db) {
            $db->beginTransaction();
            try {
                if ($db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                    $db->executeStatement('CREATE SCHEMA verification_expiry_migration_test');
                    $db->executeStatement('SET LOCAL search_path TO verification_expiry_migration_test');
                }
                $users = Table::editor()->setUnquotedName('wave_user')->setColumns(
                    Column::editor()->setUnquotedName('id')->setTypeName('integer')->create(),
                    Column::editor()->setUnquotedName('email_verified')->setTypeName('boolean')->create(),
                    Column::editor()->setUnquotedName('deleted_at')->setTypeName('datetime_immutable')->setNotNull(false)->create(),
                )->create();
                $users->setPrimaryKey(['id']);
                $tokens = Table::editor()->setUnquotedName('user_action_token')->setColumns(
                    Column::editor()->setUnquotedName('id')->setTypeName('integer')->create(),
                    Column::editor()->setUnquotedName('user_id')->setTypeName('integer')->create(),
                    Column::editor()->setUnquotedName('purpose')->setTypeName('string')->setLength(20)->create(),
                    Column::editor()->setUnquotedName('token_hash')->setTypeName('string')->setLength(64)->create(),
                    Column::editor()->setUnquotedName('expires_at')->setTypeName('datetime_immutable')->create(),
                    Column::editor()->setUnquotedName('created_at')->setTypeName('datetime_immutable')->create(),
                )->create();
                $tokens->setPrimaryKey(['id']);
                $tokens->addUniqueIndex(['token_hash'], 'uniq_user_action_token_hash');
                $tokens->addIndex(['expires_at'], 'idx_action_token_expiry');
                $tokens->addForeignKeyConstraint('wave_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
                $schema = new Schema([$users, $tokens]);
                foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) {
                    $db->executeStatement($sql);
                }
                $db->executeStatement("INSERT INTO wave_user VALUES (1, FALSE, NULL), (2, TRUE, NULL), (3, FALSE, '2020-01-01 00:00:00')");
                foreach ([1 => [1, 'verify_email'], 2 => [1, 'reset_password'], 3 => [2, 'verify_email'], 4 => [3, 'verify_email']] as $id => [$owner, $purpose]) {
                    $db->insert('user_action_token', ['id' => $id, 'user_id' => $owner, 'purpose' => $purpose, 'token_hash' => hash('sha256', (string) $id), 'expires_at' => '2020-01-02 00:00:00', 'created_at' => '2020-01-01 00:00:00']);
                }
                $migration = new \DoctrineMigrations\Version20261022140000($db, new NullLogger());
                $migration->up($db->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) {
                    $db->executeStatement($query->getStatement());
                }
                self::assertNull($db->fetchOne('SELECT expires_at FROM user_action_token WHERE id = 1'));
                self::assertSame(hash('sha256', '1'), $db->fetchOne('SELECT token_hash FROM user_action_token WHERE id = 1'));
                self::assertStringStartsWith('2020-01-02', $db->fetchOne('SELECT expires_at FROM user_action_token WHERE id = 2'));
                self::assertSame([1, 2], array_map('intval', $db->fetchFirstColumn('SELECT id FROM user_action_token ORDER BY id')));
                $table = $db->createSchemaManager()->introspectTable('user_action_token');
                self::assertFalse($table->getColumn('expires_at')->getNotnull());
                self::assertTrue($table->hasIndex('uniq_user_action_token_hash'));
                self::assertTrue($table->hasIndex('idx_action_token_expiry'));
                self::assertCount(1, $table->getForeignKeys());
            } finally {
                $db->rollBack();
            }
        }
        $sqlite->close();
    }
}
