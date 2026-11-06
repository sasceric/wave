<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReferralMigrationTest extends KernelTestCase
{
    public function testPostgresMigrationAddsReferralStorageAndPreservesExistingAccounts(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get('doctrine')->getConnection();
        require_once dirname(__DIR__, 2).'/migrations/Version20261107100000.php';
        $db->beginTransaction();
        try {
            $db->executeStatement('CREATE SCHEMA referral_migration_test');
            $db->executeStatement('SET LOCAL search_path TO referral_migration_test');
            $db->executeStatement('CREATE TABLE wave_user (id INT PRIMARY KEY, email TEXT NOT NULL)');
            $db->executeStatement('CREATE TABLE creator (id INT PRIMARY KEY, display_name TEXT NOT NULL)');
            $db->executeStatement("INSERT INTO wave_user VALUES (1, 'inviter@example.test'), (2, 'invited@example.test')");
            $db->executeStatement("INSERT INTO creator VALUES (10, 'Existing Creator')");
            $migration = new \DoctrineMigrations\Version20261107100000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
            self::assertSame('Existing Creator', $db->fetchOne('SELECT display_name FROM creator WHERE id = 10'));
            self::assertSame(0, $db->fetchOne('SELECT ambassador_tier FROM creator WHERE id = 10'));
            self::assertSame(['inviter@example.test', 'invited@example.test'], $db->fetchFirstColumn('SELECT email FROM wave_user ORDER BY id'));
            self::assertNull($db->fetchOne('SELECT referral_code FROM wave_user WHERE id = 1'));
            $db->executeStatement("UPDATE wave_user SET referral_code = 'AMAR' WHERE id = 1");
            $db->executeStatement("INSERT INTO creator_referral (referrer_id, referred_id, code, created_at) VALUES (1, 2, 'AMAR', CURRENT_TIMESTAMP)");
            $db->executeStatement('INSERT INTO referral_reward (user_id, tier, reached_at) VALUES (1, 20, CURRENT_TIMESTAMP)');
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM creator_referral'));
            self::assertNull($db->fetchOne('SELECT approved_at FROM referral_reward'));
            $indexes = $db->createSchemaManager()->listTableIndexes('creator_referral');
            self::assertTrue($indexes['uniq_creator_referral_referred']->isUnique());
            self::assertTrue($db->createSchemaManager()->listTableIndexes('referral_reward')['uniq_referral_reward_user_tier']->isUnique());
            $db->delete('wave_user', ['id' => 2]);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM creator_referral'));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM referral_reward'));
        } finally {
            $db->rollBack();
        }
    }
}
