<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aggregate QR opens by UTC day and approximate country/city, without visitor identifiers.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('wave_qr_scan_daily');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('qr_link_id', 'integer');
        $table->addColumn('day', 'date_immutable');
        $table->addColumn('country', 'string', ['length' => 2]);
        $table->addColumn('city', 'string', ['length' => 120]);
        $table->addColumn('scans', 'integer');
        $table->addColumn('last_scan_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['qr_link_id', 'day', 'country', 'city'], 'uniq_qr_scan_day_location');
        $table->addIndex(['qr_link_id']);
        $table->addForeignKeyConstraint('wave_qr_link', ['qr_link_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('wave_qr_scan_daily');
    }
}
