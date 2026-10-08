<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store permanent admin-managed QR links with editable destinations.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('wave_qr_link');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('token', 'string', ['length' => 32]);
        $table->addColumn('label', 'string', ['length' => 120]);
        $table->addColumn('destination', 'string', ['length' => 2048]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('updated_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['token'], 'uniq_qr_link_token');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('wave_qr_link');
    }
}
