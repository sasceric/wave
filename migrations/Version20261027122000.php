<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027122000 extends AbstractMigration
{
    public function getDescription(): string { return 'Refine campaign ad examples and add a single-space campaign email reminder.'; }
    public function up(Schema $schema): void
    {
        $first = [
            'name' => 'Poziv kompanijama', 'title' => 'Vaš brend. Njihova kreativnost.',
            'body' => 'Dobra saradnja počinje jasnom idejom. Objavite kampanju i upoznajte kreatore koji će je pretvoriti u priču.',
            'buttonLabel' => 'Kreiraj kampanju', 'url' => '/racun/kampanje/nova',
            'palette' => 'forest', 'span' => 2, 'artwork' => 'campaign-invitation',
            'placements' => [['row' => 1, 'column' => 2], ['row' => 5, 'column' => 2]],
        ];
        $second = [
            'name' => 'Ovdje je vaše mjesto', 'title' => 'Ovdje može biti vaša kampanja.',
            'body' => 'Prava publika već je ovdje. Šta čekate? Predstavite svoju ideju i pronađite svoj kreativni spoj.',
            'buttonLabel' => 'Objavi kampanju', 'url' => '/racun/kampanje/nova',
            'palette' => 'coral', 'span' => 1, 'artwork' => '',
            'placements' => [['row' => 3, 'column' => 4]],
        ];
        $teaser = [...$first, 'name' => 'Uskoro nove kampanje', 'title' => 'Nove kampanje stižu.',
            'body' => 'Pripremamo nove prilike za saradnju. Ostani u toku i pripremi svoj profil — možda je sljedeća kampanja baš za tebe.',
            'buttonLabel' => 'Pripremi profil', 'url' => '/racun', 'helpLabel' => '', 'helpUrl' => ''];
        $brand = [...$second, 'name' => 'Pronađite svoj kreativni spoj', 'title' => 'Objavite kampanju. Pronađite svoj spoj.',
            'body' => 'Vaš brend i prava kreativna energija. Predstavite svoju ideju i pronađite kreatore koji će je pretvoriti u priču.',
            'helpLabel' => 'Treba pomoć s objavom? Javite nam se.', 'helpUrl' => '/podrska'];
        $encode = static fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ([[$first, $teaser], [$second, $brand]] as [$original, $replacement]) {
            $this->addSql("UPDATE listing_ad SET content = ? WHERE kind = 'campaigns' AND enabled = FALSE AND image_id IS NULL AND COALESCE(content->>'helpLabel', '') = '' AND COALESCE(content->>'helpUrl', '') = '' AND content::jsonb - 'helpLabel' - 'helpUrl' = ?::jsonb", [$encode($replacement), $encode($original)]);
        }
        $reminder = [
            'name' => 'Podsjetnik za nove kampanje', 'title' => 'Ne propusti sljedeću kampanju.',
            'body' => 'U postavkama uključi e-mail obavijesti o novim kampanjama. Javit ćemo ti kada se pojave nove prilike za saradnju.',
            'buttonLabel' => 'Uključi obavijesti', 'url' => '/racun/postavke',
            'helpLabel' => '', 'helpUrl' => '', 'palette' => 'paper', 'span' => 1, 'artwork' => '',
            'placements' => [['row' => 2, 'column' => 4]],
        ];
        $this->addSql("INSERT INTO listing_ad (kind, content, enabled) SELECT 'campaigns', ?, FALSE WHERE NOT EXISTS (SELECT 1 FROM listing_ad WHERE kind = 'campaigns' AND content->>'name' = ?)", [$encode($reminder), $reminder['name']]);
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Keep administrator-owned example content when rolling back application code.');
    }
}
