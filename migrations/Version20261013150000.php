<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist trusted campaign offer and hiring events in chat history.'; }
    public function up(Schema $schema): void
    {
        $type = $this->platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform ? 'CLOB' : 'JSON';
        $this->addSql('ALTER TABLE campaign_message ADD event '.$type.' DEFAULT NULL');
    }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE campaign_message DROP COLUMN event'); }
}
