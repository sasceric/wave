<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add editable, locale-specific email templates.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('email_template');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('template_key', 'string', ['length' => 40]);
        $table->addColumn('locale', 'string', ['length' => 5]);
        $table->addColumn('subject', 'string', ['length' => 180]);
        $table->addColumn('html_body', 'text');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['template_key', 'locale'], 'uniq_email_template_key_locale');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('email_template');
    }
}
