<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use App\Migration\LegacySqlMigration;

final class Version20261005130000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Remove campaign moderation; published open campaigns are public immediately.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign DROP moderation_status');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign ADD moderation_status VARCHAR(20) DEFAULT \'approved\' NOT NULL');
    }
}
