<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Si los gastos de una partida tienen que llevar factura. Por defecto sí; se quita a
 * las cinco partidas de gasto que nunca la traen. Ingresos, traspasos y financiación
 * no se tocan: el código ya los deja fuera por el tipo del grupo.
 *
 * Por grupo Y nombre: «Formación» existe en ingresos y en administración.
 *
 * Sólo AÑADE una columna con valor por defecto: va ANTES del código al desplegar, y
 * el código viejo sigue funcionando con ella.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261016100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partidas: si sus gastos llevan factura';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_category ADD requires_invoice TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql("UPDATE budget_category c JOIN budget_category_group g ON g.id = c.group_id
            SET c.requires_invoice = 0
            WHERE (g.name = 'ADMINISTRACIÓN' AND c.name IN ('Comisiones bancarias', 'Impuestos'))
               OR (g.name = 'SUELDOS' AND c.name IN ('Nóminas', 'Seguridad Social', 'IRPF'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_category DROP requires_invoice');
    }
}
