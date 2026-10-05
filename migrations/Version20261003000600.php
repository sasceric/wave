<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use App\Migration\LegacySqlMigration;

final class Version20261003000600 extends LegacySqlMigration
{
    public function getDescription(): string
    {
        return 'Align profile media indexes with Doctrine naming.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_company_logo_media');
        $this->addSql('CREATE INDEX IDX_4FBF094FBAAE86A3 ON company (logo_media_id)');
        $this->addSql('DROP INDEX IF EXISTS idx_creator_avatar_media');
        $this->addSql('CREATE INDEX IDX_BC06EA638B224CA9 ON creator (avatar_media_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_BC06EA638B224CA9');
        $this->addSql('CREATE INDEX idx_creator_avatar_media ON creator (avatar_media_id)');
        $this->addSql('DROP INDEX IF EXISTS IDX_4FBF094FBAAE86A3');
        $this->addSql('CREATE INDEX idx_company_logo_media ON company (logo_media_id)');
    }
}
