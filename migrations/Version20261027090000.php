<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow campaigns to be hidden from public listings without closing existing collaborations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaign ADD listed BOOLEAN DEFAULT TRUE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM campaign WHERE listed = FALSE') > 0, 'Resolve hidden campaign listings before dropping their visibility setting.');
        $this->addSql('ALTER TABLE campaign DROP listed');
    }
}
