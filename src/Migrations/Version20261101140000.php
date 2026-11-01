<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El precio congelado en la cesta (`month_price`, `egg_month_price`) deja de usarse: la
 * cuota se calcula de las tarifas. El código ya no rellena esas columnas, y eran NOT
 * NULL sin valor por defecto: sin esto, guardar una cesta nueva fallaría.
 *
 * Sólo les da un valor por defecto: va ANTES del código al desplegar, y el código viejo
 * sigue escribiéndolas igual. Las columnas se borran en una migración aparte, cuando
 * este código ya esté en producción.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261101140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cestas: el precio congelado pasa a valer 0 por defecto (ya no se rellena)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE partner_basket_share MODIFY month_price NUMERIC(8, 2) NOT NULL DEFAULT '0.00', MODIFY egg_month_price NUMERIC(8, 2) NOT NULL DEFAULT '0.00'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner_basket_share MODIFY month_price NUMERIC(8, 2) NOT NULL, MODIFY egg_month_price NUMERIC(8, 2) NOT NULL');
    }
}
