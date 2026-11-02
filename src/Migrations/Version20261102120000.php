<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El día en que se anotó una factura recibida que llegó tarde, con el trimestre de su
 * fecha ya entregado a la gestoría: la pone en el paquete del trimestre siguiente.
 *
 * Sólo AÑADE una columna que admite NULL (todas las facturas que ya hay quedan como
 * estaban): va ANTES del código al desplegar.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fecha de anotación de la factura recibida que llega tarde a un trimestre entregado';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE received_invoice ADD booked_on DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE received_invoice DROP booked_on');
    }
}
