<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013130000 extends AbstractMigration
{
    public function getDescription(): string { return 'Two-level marketplace reports, private modal updates and symmetric personal block visibility.'; }
    public function up(Schema $schema): void
    {
        $date = $this->platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform ? 'DATETIME' : 'TIMESTAMP(0) WITHOUT TIME ZONE';
        $this->addSql("CREATE TABLE user_block (id VARCHAR(32) PRIMARY KEY, blocker_id INT NOT NULL REFERENCES wave_user(id) ON DELETE CASCADE, blocked_id INT NOT NULL REFERENCES wave_user(id) ON DELETE CASCADE, created_at $date NOT NULL, cleared_at $date DEFAULT NULL, CONSTRAINT chk_block_other CHECK (blocker_id <> blocked_id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_active_user_block ON user_block (blocker_id, blocked_id) WHERE cleared_at IS NULL');
        $this->addSql('CREATE INDEX idx_block_reverse ON user_block (blocked_id, blocker_id) WHERE cleared_at IS NULL');
        $this->addSql("CREATE TABLE marketplace_report (id VARCHAR(32) PRIMARY KEY, reporter_id INT NOT NULL REFERENCES wave_user(id) ON DELETE CASCADE, target_user_id INT NOT NULL REFERENCES wave_user(id) ON DELETE CASCADE, target_kind VARCHAR(24) NOT NULL, target_slug VARCHAR(200) NOT NULL, target_name VARCHAR(200) NOT NULL, category VARCHAR(40) NOT NULL, reason VARCHAR(40) NOT NULL, description TEXT NOT NULL, status VARCHAR(20) NOT NULL, response TEXT NOT NULL, version INT NOT NULL, created_at $date NOT NULL, updated_at $date NOT NULL)");
        $this->addSql('CREATE TABLE marketplace_report_media (report_id VARCHAR(32) NOT NULL REFERENCES marketplace_report(id) ON DELETE CASCADE, media_id INT NOT NULL REFERENCES media(id) ON DELETE CASCADE, PRIMARY KEY (report_id, media_id))');
        $this->addSql('CREATE INDEX idx_report_status_date ON marketplace_report (status, created_at, id)');
        $this->addSql('CREATE INDEX idx_report_reporter ON marketplace_report (reporter_id, created_at)');
        $this->addSql('CREATE INDEX idx_report_target ON marketplace_report (target_user_id)');
        $this->addSql("CREATE TABLE report_notice (id VARCHAR(32) PRIMARY KEY, recipient_id INT NOT NULL REFERENCES wave_user(id) ON DELETE CASCADE, report_id VARCHAR(32) NOT NULL REFERENCES marketplace_report(id) ON DELETE CASCADE, version INT NOT NULL, status VARCHAR(20) NOT NULL, message TEXT NOT NULL, created_at $date NOT NULL, read_at $date DEFAULT NULL)");
        $this->addSql('CREATE INDEX idx_report_notice_pending ON report_notice (recipient_id, read_at, created_at)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE report_notice');
        $this->addSql('DROP TABLE marketplace_report_media');
        $this->addSql('DROP TABLE marketplace_report');
        $this->addSql('DROP TABLE user_block');
    }
}
