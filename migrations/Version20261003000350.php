<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003000350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add creator packages, portfolio media, inquiries, chat, and editable profile catalogs.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE creator_faq (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, questions CLOB NOT NULL, answers CLOB NOT NULL, position INTEGER NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL)');
        $this->addSql('CREATE TABLE creator_inquiry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, package_id VARCHAR(64) DEFAULT NULL, package_title VARCHAR(120) DEFAULT NULL, listed_price INTEGER DEFAULT NULL, proposed_amount INTEGER DEFAULT NULL, message CLOB NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, responded_at DATETIME DEFAULT NULL, creator_id INTEGER NOT NULL, company_id INTEGER NOT NULL, CONSTRAINT FK_D4ABBA8461220EA6 FOREIGN KEY (creator_id) REFERENCES creator (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D4ABBA84979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_creator_inquiry_creator_status ON creator_inquiry (creator_id, status)');
        $this->addSql('CREATE INDEX idx_creator_inquiry_company_status ON creator_inquiry (company_id, status)');
        $this->addSql('CREATE INDEX IDX_D4ABBA8461220EA6 ON creator_inquiry (creator_id)');
        $this->addSql('CREATE INDEX IDX_D4ABBA84979B1AD6 ON creator_inquiry (company_id)');
        $this->addSql('CREATE TABLE inquiry_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, created_at DATETIME NOT NULL, inquiry_id INTEGER NOT NULL, sender_id INTEGER NOT NULL, CONSTRAINT FK_5DA05EDAA7AD6D71 FOREIGN KEY (inquiry_id) REFERENCES creator_inquiry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5DA05EDAF624B39D FOREIGN KEY (sender_id) REFERENCES wave_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_inquiry_message_inquiry_created ON inquiry_message (inquiry_id, created_at)');
        $this->addSql('CREATE INDEX IDX_5DA05EDAA7AD6D71 ON inquiry_message (inquiry_id)');
        $this->addSql('CREATE INDEX IDX_5DA05EDAF624B39D ON inquiry_message (sender_id)');
        $this->addSql('CREATE TABLE marketplace_category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, value VARCHAR(80) NOT NULL, labels CLOB NOT NULL, position INTEGER NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_marketplace_category_value ON marketplace_category (value)');
        $this->addSql('ALTER TABLE creator ADD COLUMN tagline VARCHAR(180) DEFAULT \'\' NOT NULL');
        $this->addSql('ALTER TABLE creator ADD COLUMN portfolio CLOB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE creator ADD COLUMN packages CLOB DEFAULT \'[]\' NOT NULL');

        $categories = [
            ['Beauty', ['bs' => 'Ljepota', 'hr' => 'Ljepota', 'sr' => 'Lepota', 'sl' => 'Lepota', 'en' => 'Beauty']],
            ['Fashion', ['bs' => 'Moda', 'hr' => 'Moda', 'sr' => 'Moda', 'sl' => 'Moda', 'en' => 'Fashion']],
            ['Food', ['bs' => 'Hrana', 'hr' => 'Hrana', 'sr' => 'Hrana', 'sl' => 'Hrana', 'en' => 'Food']],
            ['Lifestyle', ['bs' => 'Životni stil', 'hr' => 'Životni stil', 'sr' => 'Životni stil', 'sl' => 'Življenjski slog', 'en' => 'Lifestyle']],
            ['Travel', ['bs' => 'Putovanja', 'hr' => 'Putovanja', 'sr' => 'Putovanja', 'sl' => 'Potovanja', 'en' => 'Travel']],
            ['Wellness', ['bs' => 'Dobrobit', 'hr' => 'Dobrobit', 'sr' => 'Dobrobit', 'sl' => 'Dobro počutje', 'en' => 'Wellness']],
        ];
        foreach ($categories as $position => [$value, $labels]) {
            $this->addSql(
                'INSERT INTO marketplace_category (value, labels, position, active) VALUES (?, ?, ?, ?)',
                [$value, json_encode($labels, JSON_THROW_ON_ERROR), $position, true],
            );
        }

        $faqs = [
            [
                ['bs' => 'Kako šaljem upit za saradnju?', 'hr' => 'Kako šaljem upit za suradnju?', 'sr' => 'Kako šaljem upit za saradnju?', 'sl' => 'Kako pošljem povpraševanje za sodelovanje?', 'en' => 'How do I send a collaboration request?'],
                ['bs' => 'Izaberi paket ili pošalji opšti upit. Kreator ga može prihvatiti ili odbiti; nakon prihvatanja otvara se privatni razgovor.', 'hr' => 'Odaberi paket ili pošalji opći upit. Kreator ga može prihvatiti ili odbiti; nakon prihvaćanja otvara se privatni razgovor.', 'sr' => 'Izaberi paket ili pošalji opšti upit. Kreator može da ga prihvati ili odbije; nakon prihvatanja otvara se privatni razgovor.', 'sl' => 'Izberi paket ali pošlji splošno povpraševanje. Ustvarjalec ga lahko sprejme ali zavrne; po sprejemu se odpre zasebni pogovor.', 'en' => 'Choose a package or send a general request. The creator can accept or decline; accepting opens a private chat.'],
            ],
            [
                ['bs' => 'Šta znači „cijena na upit“?', 'hr' => 'Što znači „cijena na upit“?', 'sr' => 'Šta znači „cena na upit“?', 'sl' => 'Kaj pomeni »cena na povpraševanje«?', 'en' => 'What does “price on request” mean?'],
                ['bs' => 'Kreator će razmotriti tvoj upit i dogovoriti cijenu u razgovoru ako ga prihvati.', 'hr' => 'Kreator će razmotriti tvoj upit i dogovoriti cijenu u razgovoru ako ga prihvati.', 'sr' => 'Kreator će razmotriti tvoj upit i dogovoriti cenu u razgovoru ako ga prihvati.', 'sl' => 'Ustvarjalec bo pregledal tvoje povpraševanje in se o ceni pogovoril, če ga sprejme.', 'en' => 'The creator can review your request and discuss pricing in chat if they accept it.'],
            ],
            [
                ['bs' => 'Da li Wave potvrđuje broj pratilaca?', 'hr' => 'Potvrđuje li Wave broj pratitelja?', 'sr' => 'Da li Wave potvrđuje broj pratilaca?', 'sl' => 'Ali Wave preverja število sledilcev?', 'en' => 'Does Wave verify follower counts?'],
                ['bs' => 'Brojke unosi kreator i prikazane su kao samoprijavljene. Wave ih trenutno ne provjerava nezavisno.', 'hr' => 'Brojke unosi kreator i prikazane su kao samoprijavljene. Wave ih trenutačno ne provjerava neovisno.', 'sr' => 'Brojke unosi kreator i prikazane su kao samoprijavljene. Wave ih trenutno ne proverava nezavisno.', 'sl' => 'Podatke vnese ustvarjalec in so označeni kot samoporočani. Wave jih trenutno ne preverja neodvisno.', 'en' => 'Creators provide these figures and they are labeled self-reported. Wave does not independently verify them at this time.'],
            ],
        ];
        foreach ($faqs as $position => [$questions, $answers]) {
            $this->addSql(
                'INSERT INTO creator_faq (questions, answers, position, active) VALUES (?, ?, ?, ?)',
                [json_encode($questions, JSON_THROW_ON_ERROR), json_encode($answers, JSON_THROW_ON_ERROR), $position, true],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE creator_faq');
        $this->addSql('DROP TABLE inquiry_message');
        $this->addSql('DROP TABLE creator_inquiry');
        $this->addSql('DROP TABLE marketplace_category');
        $this->addSql('CREATE TEMPORARY TABLE __temp__creator AS SELECT id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags, translations, owner_id FROM creator');
        $this->addSql('DROP TABLE creator');
        $this->addSql('CREATE TABLE creator (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(100) NOT NULL, display_name VARCHAR(120) NOT NULL, category VARCHAR(80) NOT NULL, location VARCHAR(120) NOT NULL, bio CLOB NOT NULL, avatar_url VARCHAR(500) DEFAULT NULL, social_profiles CLOB NOT NULL, tags CLOB NOT NULL, translations CLOB DEFAULT \'{}\' NOT NULL, owner_id INTEGER DEFAULT NULL, CONSTRAINT FK_BC06EA637E3C61F9 FOREIGN KEY (owner_id) REFERENCES wave_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO creator (id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags, translations, owner_id) SELECT id, slug, display_name, category, location, bio, avatar_url, social_profiles, tags, translations, owner_id FROM __temp__creator');
        $this->addSql('DROP TABLE __temp__creator');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BC06EA637E3C61F9 ON creator (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_creator_slug ON creator (slug)');
    }
}
