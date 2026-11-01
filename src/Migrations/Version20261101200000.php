<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El precio del transporte de cada grupo de recogida, por cesta y reparto, con su
 * fecha de inicio.
 *
 * Sólo CREA una tabla nueva: va ANTES del código al desplegar, y el código viejo ni
 * la ve. Nace vacía: los precios los pone administración en Socios → Tarifas.
 * Hasta que los ponga, nadie paga transporte en la cuota calculada (el código nuevo
 * deja de leer `partner_basket_share.transport_price`, que se borra más adelante en
 * su propia migración).
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261101200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transporte por grupo de recogida con fecha de inicio';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE transport_rate (id INT AUTO_INCREMENT NOT NULL, pickup_group_id INT NOT NULL, created_by_id INT DEFAULT NULL, price_per_delivery NUMERIC(8, 2) NOT NULL, valid_from DATE NOT NULL COMMENT '(DC2Type:date_immutable)', created_at DATETIME NOT NULL, INDEX IDX_23C68BC565C2414 (pickup_group_id), INDEX IDX_23C68BCB03A8386 (created_by_id), UNIQUE INDEX uniq_transport_rate_group_from (pickup_group_id, valid_from), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE transport_rate ADD CONSTRAINT FK_23C68BC565C2414 FOREIGN KEY (pickup_group_id) REFERENCES weekly_basket_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transport_rate ADD CONSTRAINT FK_23C68BCB03A8386 FOREIGN KEY (created_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE transport_rate');
    }
}
