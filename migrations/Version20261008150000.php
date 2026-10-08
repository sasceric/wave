<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional creator types, separate from topics and social platforms.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('creator')->addColumn('creator_types', 'json', ['default' => '[]']);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('creator')->dropColumn('creator_types');
    }
}
