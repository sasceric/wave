<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013190000 extends AbstractMigration
{
    public function getDescription(): string { return 'Reserve public slugs, add URL change cooldowns and unify previous profile URLs.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE public_url_slug (kind VARCHAR(20) NOT NULL, slug VARCHAR(120) NOT NULL, PRIMARY KEY(kind, slug))');
        foreach (['creator', 'company', 'campaign'] as $table) $this->addSql("ALTER TABLE $table ADD COLUMN public_url_changed_at ".$this->platform->getDateTimeTypeDeclarationSQL([])." DEFAULT NULL");
    }
    public function postUp(Schema $schema): void
    {
        // Runs after queued schema SQL; disconnected historical migration planning remains safe.
        $routes = new \App\Localization\LocalizedRouteMap(dirname(__DIR__));
        $slugs = new \App\Service\PublicUrlSlugs($this->connection, $routes);
        $writer = new \App\Service\PublicUrlRedirectWriter($this->connection, $slugs);
        (new \App\Service\UnifyPublicUrls($this->connection, $slugs, $writer))->run();
    }
    public function down(Schema $schema): void
    {
        // Public URL history deliberately survives rollback; restoring old slugs would break links.
        foreach (['creator', 'company', 'campaign'] as $table) $this->addSql("ALTER TABLE $table DROP COLUMN public_url_changed_at");
        $this->addSql('DROP TABLE public_url_slug');
    }
}
