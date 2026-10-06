<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `received_invoice`: las facturas y tickets que entrega la gente (el fichero y lo
 * que dice), que son a la vez la cola de lectura y el archivo para la gestoría.
 *
 * Los nombres de índices y claves ajenas son los que genera Doctrine, para que
 * el esquema y el mapeo no difieran en nada.
 *
 * Sólo AÑADE una tabla: va ANTES del código al desplegar, y no rompe nada si el
 * código tarda en llegar.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'received_invoice: facturas recibidas, cola de lectura y archivo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS received_invoice (
                id INT AUTO_INCREMENT NOT NULL,
                uploaded_by_id INT DEFAULT NULL,
                suggested_category_id INT DEFAULT NULL,
                account_entry_id INT DEFAULT NULL,
                file_name VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                source VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                attempts SMALLINT DEFAULT 0 NOT NULL,
                next_attempt_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                last_error VARCHAR(255) DEFAULT NULL,
                read_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                read_by_model VARCHAR(60) DEFAULT NULL,
                document_type VARCHAR(30) DEFAULT NULL,
                invoice_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                provider_name VARCHAR(150) DEFAULT NULL,
                provider_tax_id VARCHAR(20) DEFAULT NULL,
                invoice_number VARCHAR(50) DEFAULT NULL,
                total NUMERIC(10, 2) DEFAULT NULL,
                tax_lines JSON DEFAULT NULL,
                concept VARCHAR(255) DEFAULT NULL,
                payment_method VARCHAR(20) DEFAULT NULL,
                confidence VARCHAR(10) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX UNIQ_32861D1DC2F8ABF4 (account_entry_id),
                INDEX IDX_32861D1DA2B28FE8 (uploaded_by_id),
                INDEX IDX_32861D1DDD17DE90 (suggested_category_id),
                INDEX idx_received_invoice_status (status, next_attempt_at),
                INDEX idx_received_invoice_tax_id (provider_tax_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE received_invoice ADD CONSTRAINT FK_32861D1DA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE received_invoice ADD CONSTRAINT FK_32861D1DDD17DE90 FOREIGN KEY (suggested_category_id) REFERENCES budget_category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE received_invoice ADD CONSTRAINT FK_32861D1DC2F8ABF4 FOREIGN KEY (account_entry_id) REFERENCES account_entry (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE received_invoice');
    }
}
