<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Normalize decorative Unicode in existing creator and company names without changing slugs.'; }

    public function up(Schema $schema): void
    {
        foreach (['creator' => 'display_name', 'company' => 'name'] as $table => $column) {
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
