<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028100000 extends AbstractMigration
{
    // Frozen legacy paths. LocalizedRouteMap resolves old locale prefixes to these paths.
    private const PATHS = [
        '/racun/vodic-za-profil' => ['/racun/vodic-za-profil/kreator', '/racun/vodic-za-profil/agencija', '/racun/vodic-za-profil/brend'],
        '/hr/racun/vodic-za-profil' => ['/hr/racun/vodic-za-profil/kreator', '/hr/racun/vodic-za-profil/agencija', '/hr/racun/vodic-za-profil/brend'],
        '/rs/nalog/vodic-za-profil' => ['/rs/nalog/vodic-za-profil/kreator', '/rs/nalog/vodic-za-profil/agencija', '/rs/nalog/vodic-za-profil/brend'],
        '/me/nalog/vodic-za-profil' => ['/me/nalog/vodic-za-profil/kreator', '/me/nalog/vodic-za-profil/agencija', '/me/nalog/vodic-za-profil/brend'],
        '/si/racun/vodnik-za-profil' => ['/si/racun/vodnik-za-profil/ustvarjalec', '/si/racun/vodnik-za-profil/agencija', '/si/racun/vodnik-za-profil/znamka'],
        '/en/account/profile-guide' => ['/en/account/profile-guide/creator', '/en/account/profile-guide/agency', '/en/account/profile-guide/brand'],
        '/de/konto/profil-leitfaden' => ['/de/konto/profil-leitfaden/creator', '/de/konto/profil-leitfaden/agentur', '/de/konto/profil-leitfaden/marke'],
    ];

    public function getDescription(): string { return 'Redirect legacy profile-guide query URLs to public, audience-specific paths.'; }

    public function up(Schema $schema): void
    {
        foreach (self::PATHS as $base => $targets) {
            $this->insert($base, $targets[0]);
            foreach (['creator', 'agency', 'brand'] as $index => $type) {
                $this->insert($base.'?type='.$type, $targets[$index]);
            }
        }
    }

    private function insert(string $source, string $target): void
    {
        $this->addSql("INSERT INTO url_redirect (source_path, target_path, enabled, preserve_query, origin, created_at, updated_at) VALUES (?, ?, TRUE, FALSE, 'profile-guide', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (source_path) DO NOTHING", [$source, $target]);
    }

    public function down(Schema $schema): void
    {
        foreach (self::PATHS as $base => $targets) {
            foreach (['' => $targets[0], '?type=creator' => $targets[0], '?type=agency' => $targets[1], '?type=brand' => $targets[2]] as $suffix => $target) {
                // Keep rules an administrator has changed or disabled since deployment.
                $this->addSql("DELETE FROM url_redirect WHERE source_path = ? AND target_path = ? AND origin = 'profile-guide' AND enabled = TRUE AND preserve_query = FALSE AND updated_at = created_at", [$base.$suffix, $target]);
            }
        }
    }
}
