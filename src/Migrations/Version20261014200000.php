<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los precios base de las cuotas con su fecha de inicio.
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine, para que el
 * esquema y el mapeo no difieran en nada.
 *
 * Sólo CREA una tabla nueva: va ANTES del código al desplegar, y el código viejo ni
 * la ve. Nace vacía a propósito: los precios los pone administración.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261014200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tarifas de las cuotas con fecha de inicio';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE fee_rate (id INT AUTO_INCREMENT NOT NULL, created_by_id INT DEFAULT NULL, concept VARCHAR(20) NOT NULL, price NUMERIC(8, 2) NOT NULL, valid_from DATE NOT NULL COMMENT '(DC2Type:date_immutable)', created_at DATETIME NOT NULL, INDEX IDX_B6FA33C1B03A8386 (created_by_id), UNIQUE INDEX uniq_fee_rate_concept_from (concept, valid_from), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE fee_rate ADD CONSTRAINT FK_B6FA33C1B03A8386 FOREIGN KEY (created_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fee_rate');
    }
}
