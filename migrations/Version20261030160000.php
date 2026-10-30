<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Repair decorative profile names on installations that already recorded the original normalization migration.'; }

    public function up(Schema $schema): void
    {
        // A new version is required: deployments that recorded 20261030150000 will not rerun it.
        // Already-normalized names produce no updates, so fresh installs safely run both versions.
        foreach (['creator' => 'display_name', 'company' => 'name'] as $table => $column) {
            // SQL-only migration planning can supply a partial schema without live tables.
            // There is no existing name data to backfill unless the source column exists.
            if (!$schema->hasTable($table) || !$schema->getTable($table)->hasColumn($column)) continue;
            foreach ($this->connection->iterateAssociative("SELECT id, $column AS name FROM $table") as $row) {
                $name = trim(\Normalizer::normalize($row['name'], \Normalizer::FORM_KC) ?: $row['name']);
                if ($name !== $row['name'] && mb_strlen($name) <= 120) {
                    $this->addSql("UPDATE $table SET $column = ? WHERE id = ? AND $column = ?", [$name, $row['id'], $row['name']]);
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Names retain their plain spelling; decorative variants cannot be reconstructed reliably.
        $this->abortIf(true, 'Normalized names cannot be restored to their previous decorative characters.');
    }
}
