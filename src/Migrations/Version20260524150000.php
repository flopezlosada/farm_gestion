<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crea `partner_membership_period`: tabla de episodios de pertenencia
 * por socio. Permite que un Partner tenga N períodos alta/baja a lo
 * largo del tiempo, en lugar del único par `inscription_date`/`demote_date`
 * en Partner. Imprescindible para análisis de evolución histórica
 * (gráficos de socios activos por fecha).
 *
 * `Partner.inscription_date` y `Partner.demote_date` se mantienen como
 * denormalización ("primera alta" y "última baja").
 */
final class Version20260524150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea partner_membership_period (histórico alta/baja)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE partner_membership_period (
                id INT AUTO_INCREMENT NOT NULL,
                partner_id INT NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE DEFAULT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                created DATETIME NOT NULL,
                PRIMARY KEY(id),
                INDEX idx_pmp_partner (partner_id),
                INDEX idx_pmp_dates (start_date, end_date)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('
            ALTER TABLE partner_membership_period
            ADD CONSTRAINT FK_PMP_PARTNER
            FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE partner_membership_period');
    }
}
