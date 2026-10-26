<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Facturas recibidas: a nombre de quién dice la factura que está (nombre y CIF
 * leídos), para avisar si no es la asociación.
 *
 * Sólo AÑADE columnas que admiten nulo: va ANTES del código al desplegar, y el
 * código viejo ni las ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261027180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: cliente leído en las facturas recibidas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE received_invoice ADD customer_name VARCHAR(150) DEFAULT NULL, ADD customer_tax_id VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE received_invoice DROP customer_name, DROP customer_tax_id');
    }
}
