<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007015000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store newsletter subscribers and their preferred language.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('newsletter_subscriber');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('email', 'string', ['length' => 180]);
        $table->addColumn('locale', 'string', ['length' => 5]);
        $table->addColumn('subscribed_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['email'], 'uniq_newsletter_subscriber_email');
        $table->addIndex(['subscribed_at', 'id'], 'idx_newsletter_subscriber_date');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('newsletter_subscriber');
    }
}
