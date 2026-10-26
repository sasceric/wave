<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email templates: optional subject and HTML (null keeps the built-in one) and admin wording for text variables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_template ALTER COLUMN subject DROP NOT NULL');
        $this->addSql('ALTER TABLE email_template ALTER COLUMN html_body DROP NOT NULL');
        $this->addSql('ALTER TABLE email_template ADD COLUMN copy_overrides JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM email_template WHERE subject IS NULL OR html_body IS NULL OR copy_overrides IS NOT NULL') > 0,
            'Cannot roll back while text-only customizations exist. Export and resolve these templates first; they will not be deleted automatically.',
        );
        $this->addSql('ALTER TABLE email_template DROP COLUMN copy_overrides');
        $this->addSql('ALTER TABLE email_template ALTER COLUMN html_body SET NOT NULL');
        $this->addSql('ALTER TABLE email_template ALTER COLUMN subject SET NOT NULL');
    }
}
