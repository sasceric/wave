<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\ParameterType;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand the shared marketplace area catalog while preserving admin edits and existing selections.';
    }

    public function up(Schema $schema): void
    {
        $source = file_get_contents(__DIR__.'/data/marketplace-areas-20261007.json');
        if ($source === false) {
            throw new \RuntimeException('The shared marketplace catalog migration data is missing.');
        }
        $areas = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
        foreach ($areas as $area) {
            $this->addSql(
                'INSERT INTO marketplace_category (value, labels, position, active) SELECT ?, ?, (SELECT COALESCE(MAX(position), -1) + 1 FROM marketplace_category), ? WHERE NOT EXISTS (SELECT 1 FROM marketplace_category WHERE LOWER(value) = LOWER(?))',
                [$area['value'], json_encode($area['labels'], JSON_THROW_ON_ERROR), true, $area['value']],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::STRING],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Shared areas may be selected by profiles and campaigns; retain them rather than delete referenced values.');
    }
}
