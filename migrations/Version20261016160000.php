<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add German labels to the standard marketplace areas without overwriting existing translations.';
    }

    public function up(Schema $schema): void
    {
        $labels = json_decode((string) file_get_contents(__DIR__.'/data/marketplace-labels-de-20261016.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($labels as $value => $label) {
            if ($this->platform instanceof PostgreSQLPlatform) {
                $this->addSql("UPDATE marketplace_category SET labels = jsonb_set(labels::jsonb, '{de}', CAST(? AS jsonb)) WHERE value = ? AND labels::jsonb ->> 'de' IS NULL", [json_encode($label, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $value]);
            } else {
                $this->addSql("UPDATE marketplace_category SET labels = json_set(labels, '$.de', ?) WHERE value = ? AND json_extract(labels, '$.de') IS NULL", [$label, $value]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Keep translations: removing German support must not erase administrator edits.
    }
}
