<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link review notifications to the exact collaboration for private feedback actions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wave_notification ADD COLUMN review_application_id INT DEFAULT NULL REFERENCES campaign_application (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_notification_review_application ON wave_notification (review_application_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notification_review_application');
        $this->addSql('ALTER TABLE wave_notification DROP COLUMN review_application_id');
    }
}
