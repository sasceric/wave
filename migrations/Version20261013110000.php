<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Protect retrievable credit codes and reserve emailed vouchers for an account without adding credits.';
    }

    public function up(Schema $schema): void
    {
        $date = $this->platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform ? 'DATETIME' : 'TIMESTAMP(0) WITHOUT TIME ZONE';
        $this->addSql('ALTER TABLE credit_voucher ADD COLUMN encrypted_code TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE credit_voucher ADD COLUMN assigned_to_id INT DEFAULT NULL REFERENCES wave_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE credit_voucher ADD COLUMN assigned_at '.$date.' DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_voucher_assigned_to ON credit_voucher (assigned_to_id)');
        $this->addSql('CREATE INDEX idx_voucher_created_id ON credit_voucher (created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_voucher_assigned_to');
        $this->addSql('DROP INDEX idx_voucher_created_id');
        $this->addSql('ALTER TABLE credit_voucher DROP COLUMN assigned_at');
        $this->addSql('ALTER TABLE credit_voucher DROP COLUMN assigned_to_id');
        $this->addSql('ALTER TABLE credit_voucher DROP COLUMN encrypted_code');
    }
}
