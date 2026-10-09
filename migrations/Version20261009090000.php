<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store support tickets, private attachment metadata and unguessable tracking links.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('wave_support_ticket');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        foreach (['tracking_token' => 64, 'submission_key' => 64, 'name' => 120, 'email' => 180, 'phone' => 40, 'kind' => 20, 'category' => 30, 'title' => 160, 'locale' => 3, 'status' => 20] as $name => $length) {
            $table->addColumn($name, 'string', ['length' => $length]);
        }
        $table->addColumn('description', 'text');
        $table->addColumn('attachments', 'json');
        $table->addColumn('receipt_sent', 'boolean');
        $table->addColumn('created_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['tracking_token'], 'uniq_support_tracking');
        $table->addUniqueIndex(['submission_key'], 'uniq_support_submission');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('wave_support_ticket');
    }
}
