<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extractos del banco en Norma 43: cada extracto subido (cuenta, fechas, saldos) y los
 * movimientos que trae, con su huella para no duplicarlos y el apunte que los anota.
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine, para que el esquema
 * y el mapeo no difieran en nada.
 *
 * Sólo CREA dos tablas nuevas: va ANTES del código al desplegar, y el código viejo ni
 * las ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin al
 * desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261031180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: extractos del banco (Norma 43) y sus movimientos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE bank_statement (id INT AUTO_INCREMENT NOT NULL, account_id INT NOT NULL, imported_by_id INT DEFAULT NULL, date_from DATE NOT NULL COMMENT '(DC2Type:date_immutable)', date_to DATE NOT NULL COMMENT '(DC2Type:date_immutable)', opening_balance NUMERIC(10, 2) NOT NULL, closing_balance NUMERIC(10, 2) NOT NULL, file_name VARCHAR(255) NOT NULL, imported_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_B33607EF74953CEA (imported_by_id), UNIQUE INDEX uniq_bank_statement_period (account_id, date_from, date_to), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE bank_statement_line (id INT AUTO_INCREMENT NOT NULL, account_id INT NOT NULL, entry_id INT DEFAULT NULL, operation_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', value_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', amount NUMERIC(10, 2) NOT NULL, common_concept VARCHAR(2) NOT NULL, own_concept VARCHAR(3) NOT NULL, document VARCHAR(10) NOT NULL, reference1 VARCHAR(12) NOT NULL, reference2 VARCHAR(16) NOT NULL, details LONGTEXT DEFAULT NULL, fingerprint VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_546CC803BA364942 (entry_id), INDEX idx_bank_statement_line_account_date (account_id, operation_date), UNIQUE INDEX uniq_bank_statement_line_fingerprint (fingerprint), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE bank_statement ADD CONSTRAINT FK_B33607EF9B6B5FBA FOREIGN KEY (account_id) REFERENCES financial_account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE bank_statement ADD CONSTRAINT FK_B33607EF74953CEA FOREIGN KEY (imported_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bank_statement_line ADD CONSTRAINT FK_546CC8039B6B5FBA FOREIGN KEY (account_id) REFERENCES financial_account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE bank_statement_line ADD CONSTRAINT FK_546CC803BA364942 FOREIGN KEY (entry_id) REFERENCES account_entry (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bank_statement_line');
        $this->addSql('DROP TABLE bank_statement');
    }
}
