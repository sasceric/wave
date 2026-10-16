<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Localization\LocalizedRouteMap;
use App\Service\PublicUrlSlugs;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore the owner-confirmed legacy Suad profile URLs to the current public profile.';
    }

    public function up(Schema $schema): void {}

    public function postUp(Schema $schema): void
    {
        // This association was confirmed by the owner. Never infer ownership from
        // a similar name: deleted accounts may belong to somebody else.
        $old = 'suad-asceric-de986b';
        $new = 'suad-asceric';
        $exists = $this->connection->fetchOne('SELECT p.id FROM creator p LEFT JOIN wave_user u ON u.id = p.owner_id WHERE p.slug = ? AND u.deleted_at IS NULL', [$new]);
        if ($exists === false) return;

        $routes = new LocalizedRouteMap(dirname(__DIR__));
        $slugs = new PublicUrlSlugs($this->connection, $routes);
        $target = $slugs->path('creator', $new);
        // A deliberate redirect on the current URL could otherwise form a cycle.
        if ($this->connection->fetchOne('SELECT 1 FROM url_redirect WHERE source_path = ? AND enabled = TRUE', [$target]) !== false) return;
        $slugs->remember('creator', $old);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $listings = array_values(array_unique(array_map(fn (string $locale): string => $routes->localizedPath('creators', $locale), $routes->locales())));
        $listings = [...$listings, '/creators', '/sr/kreatori', '/cnr/kreatori', '/sl/ustvarjalci'];

        foreach ($slugs->sources('creator', $old) as $source) {
            // Preserve disabled rules, deliberate destinations and manual rules.
            $this->connection->executeStatement(
                "UPDATE url_redirect SET target_path = ?, origin = 'url-repaired', updated_at = ? WHERE source_path = ? AND enabled = TRUE AND origin IN ('deleted-creator', 'url-unified', 'url-change') AND target_path IN (?)",
                [$target, $now, $source, $listings],
                [3 => ArrayParameterType::STRING],
            );
            $this->connection->executeStatement(
                "INSERT INTO url_redirect (source_path, target_path, enabled, preserve_query, origin, created_at, updated_at) SELECT ?, ?, TRUE, FALSE, 'url-repaired', ?, ? WHERE NOT EXISTS (SELECT 1 FROM url_redirect WHERE source_path = ?)",
                [$source, $target, $now, $now, $source],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Keep repaired public links; rollback must not restore the wrong destination.
    }
}
