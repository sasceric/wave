<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027121000 extends AbstractMigration
{
    public function getDescription(): string { return 'Install two inactive campaign ad examples, including a wide illustrated card in position two.'; }
    public function up(Schema $schema): void
    {
        $first = [
            'name' => 'Poziv kompanijama', 'title' => 'Vaš brend. Njihova kreativnost.',
            'body' => 'Dobra saradnja počinje jasnom idejom. Objavite kampanju i upoznajte kreatore koji će je pretvoriti u priču.',
            'buttonLabel' => 'Kreiraj kampanju', 'url' => '/racun/kampanje/nova',
            'palette' => 'forest', 'span' => 2, 'artwork' => 'campaign-invitation',
            'helpLabel' => '', 'helpUrl' => '',
            'placements' => [['row' => 1, 'column' => 2], ['row' => 5, 'column' => 2]],
        ];
        // Replace only our untouched inactive starter. Never overwrite an administrator's edits.
        $original = ['name' => 'Napravi prvi korak', 'title' => 'Tvoja ideja. Prava prilika.', 'body' => 'Predstavi svoj rad i pronađi saradnju koja ti odgovara.', 'buttonLabel' => 'Uredi profil', 'url' => '/racun', 'palette' => 'sage', 'span' => 2, 'placements' => [['row' => 2, 'column' => 1], ['row' => 5, 'column' => 1]]];
        $encode = static fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->addSql("UPDATE listing_ad SET content = ? WHERE kind = 'campaigns' AND enabled = FALSE AND image_id IS NULL AND content::jsonb - 'artwork' - 'helpLabel' - 'helpUrl' = ?::jsonb", [$encode($first), $encode($original)]);
        $this->addSql("INSERT INTO listing_ad (kind, content, enabled) SELECT 'campaigns', ?, FALSE WHERE NOT EXISTS (SELECT 1 FROM listing_ad WHERE kind = 'campaigns' AND content->>'name' = ?)", [$encode($first), $first['name']]);
        $second = [
            'name' => 'Ovdje je vaše mjesto', 'title' => 'Ovdje može biti vaša kampanja.',
            'body' => 'Prava publika već je ovdje. Šta čekate? Predstavite svoju ideju i pronađite svoj kreativni spoj.',
            'buttonLabel' => 'Objavi kampanju', 'url' => '/racun/kampanje/nova',
            'palette' => 'coral', 'span' => 1, 'artwork' => '',
            'helpLabel' => '', 'helpUrl' => '',
            'placements' => [['row' => 3, 'column' => 4]],
        ];
        $this->addSql("INSERT INTO listing_ad (kind, content, enabled) SELECT 'campaigns', ?, FALSE WHERE NOT EXISTS (SELECT 1 FROM listing_ad WHERE kind = 'campaigns' AND content->>'name' = ?)", [$encode($second), $second['name']]);
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Keep administrator-owned campaign examples when rolling back application code.');
    }
}
