<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Facturas que emite la asociación.
 *
 * `customer`: a quién se le factura, para no volver a teclear nombre y CIF.
 * `issued_invoice`: la factura; borrador sin número hasta que se emite, y entonces
 * número correlativo por serie y año (índice único) y copia de los datos del cliente.
 * `issued_invoice_line`: sus líneas, cada una con su tipo de IVA.
 *
 * Los nombres de índices y claves ajenas son los que genera Doctrine, para que
 * el esquema y el mapeo no difieran en nada.
 *
 * Sólo AÑADE tablas: va ANTES del código al desplegar, y el código viejo sigue
 * funcionando con ella.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261012120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Facturas emitidas: customer, issued_invoice e issued_invoice_line';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS customer (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                tax_id VARCHAR(20) DEFAULT NULL,
                address VARCHAR(255) DEFAULT NULL,
                postal_code VARCHAR(10) DEFAULT NULL,
                town VARCHAR(100) DEFAULT NULL,
                province VARCHAR(100) DEFAULT NULL,
                invoice_note LONGTEXT DEFAULT NULL,
                email VARCHAR(180) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX UNIQ_81398E09B2A824D8 (tax_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS issued_invoice (
                id INT AUTO_INCREMENT NOT NULL,
                customer_id INT DEFAULT NULL,
                rectifies_id INT DEFAULT NULL,
                created_by_id INT DEFAULT NULL,
                issued_by_id INT DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                series VARCHAR(10) NOT NULL,
                number INT DEFAULT NULL,
                year SMALLINT DEFAULT NULL,
                issue_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                description LONGTEXT DEFAULT NULL,
                exemption_note LONGTEXT DEFAULT NULL,
                customer_name VARCHAR(255) DEFAULT NULL,
                customer_tax_id VARCHAR(20) DEFAULT NULL,
                customer_address VARCHAR(255) DEFAULT NULL,
                customer_postal_code VARCHAR(10) DEFAULT NULL,
                customer_town VARCHAR(100) DEFAULT NULL,
                customer_province VARCHAR(100) DEFAULT NULL,
                customer_note LONGTEXT DEFAULT NULL,
                source VARCHAR(20) NOT NULL,
                issued_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_9FE1829D9395C3F3 (customer_id),
                INDEX IDX_9FE1829DBCD9B964 (rectifies_id),
                INDEX IDX_9FE1829DB03A8386 (created_by_id),
                INDEX IDX_9FE1829D784BB717 (issued_by_id),
                UNIQUE INDEX uniq_issued_invoice_number (series, year, number),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS issued_invoice_line (
                id INT AUTO_INCREMENT NOT NULL,
                invoice_id INT NOT NULL,
                position SMALLINT NOT NULL,
                concept LONGTEXT NOT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                vat_rate NUMERIC(5, 2) NOT NULL,
                INDEX IDX_BC0E75BE2989F1FD (invoice_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE issued_invoice ADD CONSTRAINT FK_9FE1829D9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE issued_invoice ADD CONSTRAINT FK_9FE1829DBCD9B964 FOREIGN KEY (rectifies_id) REFERENCES issued_invoice (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE issued_invoice ADD CONSTRAINT FK_9FE1829DB03A8386 FOREIGN KEY (created_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE issued_invoice ADD CONSTRAINT FK_9FE1829D784BB717 FOREIGN KEY (issued_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE issued_invoice_line ADD CONSTRAINT FK_BC0E75BE2989F1FD FOREIGN KEY (invoice_id) REFERENCES issued_invoice (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS issued_invoice_line');
        $this->addSql('DROP TABLE IF EXISTS issued_invoice');
        $this->addSql('DROP TABLE IF EXISTS customer');
    }
}
