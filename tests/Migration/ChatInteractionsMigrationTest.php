<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261025140000;
use DoctrineMigrations\Version20261025150000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ChatInteractionsMigrationTest extends KernelTestCase
{
    public function testPostgreSqlMigrationPreservesMessagesAndCanBeReversed(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $this->checkMigration($db, 'TEMP');
        } finally {
            $db->rollBack();
        }
    }

    public function testHistoricalSqliteMigrationPreservesMessagesAndCanBeReversed(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('PRAGMA foreign_keys = ON');
        $this->checkMigration($db, '');
    }

    private function checkMigration(Connection $db, string $temporary): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261025140000.php';
        require_once dirname(__DIR__, 2).'/migrations/Version20261025150000.php';
        foreach (['campaign_message', 'inquiry_message'] as $table) {
            $db->executeStatement("CREATE $temporary TABLE $table (id INT PRIMARY KEY, body TEXT NOT NULL)");
            $db->executeStatement("INSERT INTO $table (id, body) VALUES (1, 'Original'), (2, 'Reply')");
        }
        $up = new Version20261025140000($db, new NullLogger());
        $up->up(new Schema());
        foreach ($up->getSql() as $query) $db->executeStatement($query->getStatement());
        $edits = new Version20261025150000($db, new NullLogger());
        $edits->up(new Schema());
        foreach ($edits->getSql() as $query) $db->executeStatement($query->getStatement());
        foreach (['campaign_message', 'inquiry_message'] as $table) {
            self::assertSame(['Original', 'Reply'], $db->fetchFirstColumn("SELECT body FROM $table ORDER BY id"));
            self::assertSame('[]', $db->fetchOne("SELECT hearts FROM $table WHERE id = 1"));
            self::assertNull($db->fetchOne("SELECT reply_to_id FROM $table WHERE id = 1"));
            $db->executeStatement("UPDATE $table SET reply_to_id = 1, hearts = '[3]' WHERE id = 2");
            $db->executeStatement("DELETE FROM $table WHERE id = 1");
            self::assertNull($db->fetchOne("SELECT reply_to_id FROM $table WHERE id = 2"));
            self::assertSame('[3]', $db->fetchOne("SELECT hearts FROM $table WHERE id = 2"));
            self::assertNull($db->fetchOne("SELECT edited_at FROM $table WHERE id = 2"));
            self::assertNull($db->fetchOne("SELECT deleted_at FROM $table WHERE id = 2"));
        }
        $undoEdits = new Version20261025150000($db, new NullLogger());
        $undoEdits->down(new Schema());
        foreach ($undoEdits->getSql() as $query) $db->executeStatement($query->getStatement());
        $down = new Version20261025140000($db, new NullLogger());
        $down->down(new Schema());
        foreach ($down->getSql() as $query) $db->executeStatement($query->getStatement());
        foreach (['campaign_message', 'inquiry_message'] as $table) {
            self::assertSame([['id' => 2, 'body' => 'Reply']], $db->fetchAllAssociative("SELECT * FROM $table"));
        }
    }
}
