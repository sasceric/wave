<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep email verification links valid until confirmation, without changing password reset expiry.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('user_action_token')) {
            return;
        }
        $before = $schema->getTable('user_action_token');
        $after = $before->edit()->modifyColumnByUnquotedName('expires_at', static function (ColumnEditor $column): void {
            $column->setNotNull(false);
        })->create();
        $comparator = $this->connection->createSchemaManager()->createComparator(
            (new ComparatorConfig())->withReportModifiedIndexes(false),
        );
        foreach ($this->platform->getAlterTableSQL($comparator->compareTables($before, $after)) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql("DELETE FROM user_action_token WHERE purpose = 'verify_email' AND user_id IN (SELECT id FROM wave_user WHERE email_verified = TRUE OR deleted_at IS NOT NULL)");
        $this->addSql("UPDATE user_action_token SET expires_at = NULL WHERE purpose = 'verify_email'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Existing verification links must retain their validity.');
    }
}
