<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Use one email template for all four profile tips emails; keep edits made to the first one.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM email_template WHERE template_key IN ('profile_reminder_2', 'profile_reminder_3', 'profile_reminder_4')");
        $this->addSql("UPDATE email_template SET template_key = 'profile_reminder' WHERE template_key = 'profile_reminder_1'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE email_template SET template_key = 'profile_reminder_1' WHERE template_key = 'profile_reminder'");
    }
}
