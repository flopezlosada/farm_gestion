<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lo que cada socix paga de más o de menos sobre la tarifa (por meses) y las
 * iniciativas a las que puede destinar lo que paga de más.
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine, para que el
 * esquema y el mapeo no difieran en nada.
 *
 * Sólo CREA tablas nuevas: va ANTES del código al desplegar, y el código viejo ni
 * las ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261014220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de cuota por socix e iniciativas de donación';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE donation_initiative (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, is_open TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_432261B65E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE fee_adjustment (id INT AUTO_INCREMENT NOT NULL, partner_id INT NOT NULL, initiative_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, amount NUMERIC(8, 2) NOT NULL, reason VARCHAR(255) NOT NULL, from_month DATE NOT NULL COMMENT '(DC2Type:date_immutable)', until_month DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', created_at DATETIME NOT NULL, INDEX IDX_87BCF68F9393F8FE (partner_id), INDEX IDX_87BCF68FAB7D9771 (initiative_id), INDEX IDX_87BCF68FB03A8386 (created_by_id), INDEX idx_fee_adjustment_from (from_month), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE fee_adjustment ADD CONSTRAINT FK_87BCF68F9393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fee_adjustment ADD CONSTRAINT FK_87BCF68FAB7D9771 FOREIGN KEY (initiative_id) REFERENCES donation_initiative (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE fee_adjustment ADD CONSTRAINT FK_87BCF68FB03A8386 FOREIGN KEY (created_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fee_adjustment');
        $this->addSql('DROP TABLE donation_initiative');
    }
}
