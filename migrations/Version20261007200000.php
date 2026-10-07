<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migration\LegacySqlMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20261007200000 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Reversible credit settings, wallets, ledger, single-use vouchers and activation email outbox.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE credit_settings (id INTEGER NOT NULL PRIMARY KEY, paid BOOLEAN NOT NULL, unit_price_minor INTEGER NOT NULL, application_cost INTEGER NOT NULL, campaign_cost INTEGER NOT NULL, welcome_grant INTEGER NOT NULL, activated_at DATETIME DEFAULT NULL, activation INTEGER NOT NULL, version INTEGER NOT NULL)');
        $this->addSql('INSERT INTO credit_settings (id, paid, unit_price_minor, application_cost, campaign_cost, welcome_grant, activation, version) VALUES (1, false, 20, 10, 30, 0, 0, 1)');
        $this->addSql('CREATE TABLE credit_wallet (user_id INTEGER NOT NULL PRIMARY KEY, balance INTEGER NOT NULL CHECK (balance >= 0), FOREIGN KEY (user_id) REFERENCES wave_user (id) ON DELETE CASCADE)');
        $this->addSql('CREATE TABLE credit_entry (id VARCHAR(32) NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, sequence INTEGER NOT NULL, amount INTEGER NOT NULL, balance_after INTEGER NOT NULL, kind VARCHAR(30) NOT NULL, reference VARCHAR(255) NOT NULL, event_key VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY (user_id) REFERENCES wave_user (id) ON DELETE CASCADE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_credit_event ON credit_entry (event_key)');
        $this->addSql('CREATE INDEX idx_credit_entry_user_created ON credit_entry (user_id, sequence)');
        $this->addSql('CREATE TABLE credit_voucher (id VARCHAR(32) NOT NULL PRIMARY KEY, code_hash VARCHAR(64) NOT NULL, suffix VARCHAR(4) NOT NULL, amount INTEGER NOT NULL, price_minor INTEGER NOT NULL, issued_by_id INTEGER DEFAULT NULL, redeemed_by_id INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, redeemed_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, FOREIGN KEY (issued_by_id) REFERENCES wave_user (id) ON DELETE SET NULL, FOREIGN KEY (redeemed_by_id) REFERENCES wave_user (id) ON DELETE SET NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_credit_voucher_hash ON credit_voucher (code_hash)');
        $this->addSql('CREATE TABLE credit_announcement (id VARCHAR(64) NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, grant_amount INTEGER NOT NULL, application_cost INTEGER NOT NULL, campaign_cost INTEGER NOT NULL, activated_at DATETIME NOT NULL, queued_at DATETIME DEFAULT NULL, FOREIGN KEY (user_id) REFERENCES wave_user (id) ON DELETE CASCADE)');
    }

    public function down(Schema $schema): void
    {
        foreach (['credit_announcement', 'credit_voucher', 'credit_entry', 'credit_wallet', 'credit_settings'] as $table) {
            $this->addSql('DROP TABLE ' . $table);
        }
    }
}
