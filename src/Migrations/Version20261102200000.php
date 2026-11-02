<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Borra el precio congelado de la cesta (`month_price`, `egg_month_price`): la cuota se
 * calcula de las tarifas y ninguna entidad mapea ya esas columnas
 * ({@see Version20261101140000} les dio un valor por defecto para poder dejar de
 * escribirlas).
 *
 * Es un DROP: va DESPUÉS del código. En un entorno que todavía sirva código que mapee
 * estas columnas, toda lectura de una cesta daría 500.
 *
 * Los catálogos (basket_share, egg_period, egg_amount) conservan su `month_price`: siguen
 * mapeados y se retiran aparte.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261102200000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Cestas: se borra el precio congelado (month_price, egg_month_price)';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner_basket_share DROP month_price, DROP egg_month_price');
    }

    /**
     * Las vuelve a crear a 0: el precio que tenían no se recupera.
     */
    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE partner_basket_share ADD month_price NUMERIC(8, 2) NOT NULL DEFAULT '0.00', ADD egg_month_price NUMERIC(8, 2) NOT NULL DEFAULT '0.00'");
    }
}
