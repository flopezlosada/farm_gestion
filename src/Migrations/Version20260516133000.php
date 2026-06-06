<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bloque 5: añade Partner.status (string), pieza central del workflow de
 * pertenencia (ACTIVO / PAUSADO / BAJA). is_active se mantiene como mirror
 * temporal hasta migrar todas las queries legacy.
 *
 * Datos heredados: los socios con is_active = 0 o NULL pasan a BAJA; el
 * resto arranca en ACTIVO. PAUSADO no se infiere — se asigna a mano cuando
 * se identifique en la migración de datos real.
 */
final class Version20260516133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partner.status (ACTIVO/PAUSADO/BAJA) + backfill desde is_active';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on \'mysql\'.'
        );

        $this->addSql("ALTER TABLE partner ADD status VARCHAR(20) NOT NULL DEFAULT 'ACTIVO'");
        $this->addSql("UPDATE partner SET status = 'BAJA' WHERE is_active IS NULL OR is_active = 0");
        $this->addSql("CREATE INDEX idx_partner_status ON partner (status)");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on \'mysql\'.'
        );

        $this->addSql('DROP INDEX idx_partner_status ON partner');
        $this->addSql('ALTER TABLE partner DROP status');
    }
}
