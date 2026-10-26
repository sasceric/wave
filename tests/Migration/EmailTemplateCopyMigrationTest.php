<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmailTemplateCopyMigrationTest extends KernelTestCase
{
    public function testPostgresMigrationPreservesSavedLanguagesAndTextOnlyChanges(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get('doctrine')->getConnection();
        self::assertInstanceOf(PostgreSQLPlatform::class, $db->getDatabasePlatform());
        require_once dirname(__DIR__, 2).'/migrations/Version20261026150000.php';
        $db->beginTransaction();
        try {
            $db->executeStatement('CREATE SCHEMA email_template_copy_test');
            $db->executeStatement('SET LOCAL search_path TO email_template_copy_test');
            $db->executeStatement('CREATE TABLE email_template (id INT PRIMARY KEY, template_key VARCHAR(40) NOT NULL, locale VARCHAR(5) NOT NULL, subject VARCHAR(180) NOT NULL, html_body TEXT NOT NULL)');
            $db->executeStatement("INSERT INTO email_template VALUES (1, 'verify', 'bs', 'Original subject', '<p>Original layout</p>')");
            $migration = new \DoctrineMigrations\Version20261026150000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement());
            $row = $db->fetchAssociative('SELECT * FROM email_template WHERE id = 1');
            self::assertSame('Original subject', $row['subject']);
            self::assertSame('<p>Original layout</p>', $row['html_body']);
            self::assertNull($row['copy_overrides']);
            $db->executeStatement("INSERT INTO email_template VALUES (2, 'verify', 'en', NULL, NULL, ?)", ['{"heading":"Custom heading"}']);
            self::assertSame(['heading' => 'Custom heading'], json_decode($db->fetchOne('SELECT copy_overrides FROM email_template WHERE id = 2'), true));
            try {
                (new \DoctrineMigrations\Version20261026150000($db, new NullLogger()))->down(new Schema());
                self::fail('Rollback must not silently delete wording customizations.');
            } catch (AbortMigration $exception) {
                self::assertStringContainsString('will not be deleted', $exception->getMessage());
            }
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM email_template'));
        } finally {
            $db->rollBack();
        }
    }
}
