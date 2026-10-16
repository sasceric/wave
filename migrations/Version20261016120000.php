<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add opt-in campaign alerts and account activation milestones without changing existing profiles.'; }
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('campaign_alert_preference');
        $table->addColumn('user_id', 'integer');
        $table->addColumn('enabled', 'boolean');
        $table->addColumn('categories', 'json');
        $table->addColumn('countries', 'json');
        $table->addColumn('frequency', 'string', ['length' => 10]);
        $table->addColumn('checked_at', 'datetime_immutable');
        $table->setPrimaryKey(['user_id']);
        $table->addForeignKeyConstraint('wave_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table = $schema->createTable('account_milestone');
        $table->addColumn('user_id', 'integer');
        $table->addColumn('stage', 'string', ['length' => 20]);
        $table->addColumn('occurred_at', 'datetime_immutable');
        $table->setPrimaryKey(['user_id', 'stage']);
        $table->addIndex(['stage', 'occurred_at'], 'idx_account_milestone_stage_at');
        $table->addForeignKeyConstraint('wave_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
    }
    public function down(Schema $schema): void { $schema->dropTable('campaign_alert_preference'); $schema->dropTable('account_milestone'); }
}
