<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las remesas de recibos domiciliados (el Excel que se sube a Fiare cada mes) y sus
 * recibos.
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
final class Version20261012200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remesas de recibos domiciliados';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE remittance (id INT AUTO_INCREMENT NOT NULL, sent_by_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, kind VARCHAR(20) NOT NULL, period DATE NOT NULL COMMENT '(DC2Type:date_immutable)', label VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL, source VARCHAR(20) NOT NULL, imported_from VARCHAR(255) DEFAULT NULL, sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL, INDEX IDX_7C389743A45BB98C (sent_by_id), INDEX IDX_7C389743B03A8386 (created_by_id), UNIQUE INDEX uniq_remittance_kind_period (kind, period), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE remittance_line (id INT AUTO_INCREMENT NOT NULL, remittance_id INT NOT NULL, partner_id INT DEFAULT NULL, debtor_name VARCHAR(70) NOT NULL, iban VARCHAR(34) NOT NULL, mandate_reference VARCHAR(35) NOT NULL, mandate_signed_on DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', debit_reference VARCHAR(35) NOT NULL, sequence_type VARCHAR(4) NOT NULL, amount NUMERIC(10, 2) NOT NULL, INDEX IDX_1A07BDBB29F5296 (remittance_id), INDEX IDX_1A07BDBB9393F8FE (partner_id), INDEX idx_remittance_line_mandate (mandate_reference), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE remittance ADD CONSTRAINT FK_7C389743A45BB98C FOREIGN KEY (sent_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE remittance ADD CONSTRAINT FK_7C389743B03A8386 FOREIGN KEY (created_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE remittance_line ADD CONSTRAINT FK_1A07BDBB29F5296 FOREIGN KEY (remittance_id) REFERENCES remittance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE remittance_line ADD CONSTRAINT FK_1A07BDBB9393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE remittance_line');
        $this->addSql('DROP TABLE remittance');
    }
}
