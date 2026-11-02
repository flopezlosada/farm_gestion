<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La huella del fichero de cada factura recibida, para avisar del mismo documento
 * subido dos veces.
 *
 * Sólo AÑADE una columna que admite NULL y su índice: va ANTES del código al
 * desplegar. Las facturas que ya hay quedan sin huella (no se comparan).
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261102140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Huella del fichero de la factura recibida';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE received_invoice ADD file_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_received_invoice_file_hash ON received_invoice (file_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_received_invoice_file_hash ON received_invoice');
        $this->addSql('ALTER TABLE received_invoice DROP file_hash');
    }
}
