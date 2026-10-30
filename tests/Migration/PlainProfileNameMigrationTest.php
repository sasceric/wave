<?php

namespace App\Tests\Migration;

use Doctrine\DBAL\Schema\Schema;
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
            $migration = new \DoctrineMigrations\Version20261030150000($db, new NullLogger()); $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
            self::assertSame('Amila Čačić', $db->fetchOne('SELECT display_name FROM creator WHERE id = 1'));
            self::assertSame('Šehić Đurić Željko', $db->fetchOne('SELECT display_name FROM creator WHERE id = 2'));
            self::assertSame('Studio', $db->fetchOne('SELECT name FROM company WHERE id = 1'));
            self::assertSame('keep-creator-slug', $db->fetchOne('SELECT slug FROM creator WHERE id = 1'));
            $again = new \DoctrineMigrations\Version20261030150000($db, new NullLogger()); $again->up(new Schema()); self::assertSame([], $again->getSql());
        } finally { $db->rollBack(); }
    }
}
