<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Módulo GRUPO DE CONSUMO — v1 (2026-07-02): pedidos colectivos de productos de
 * terceros que se reparten con la cesta, con CATÁLOGO persistente de productores.
 *
 * Tablas:
 *   - `consumer_group_producer`:    productor persistente (datos + modo de gestión
 *     self_managed). Reutilizable entre rondas.
 *   - `consumer_group_product`:     catálogo del productor (nombre, unidad, precio
 *     de referencia, activo). FK a producer CASCADE.
 *   - `consumer_group_round`:       la ronda (FK a producer y a autor). Estado,
 *     cierre, entrega, condición de mínimo.
 *   - `consumer_group_round_item`:  producto incluido en una ronda con su PRECIO de
 *     ronda. FK a round CASCADE, a product RESTRICT (protege histórico). UNIQUE
 *     (round, product).
 *   - `consumer_group_order`:       pedido de una socia. UNIQUE (round, partner).
 *   - `consumer_group_order_line`:  cantidad de un round_item en un pedido. UNIQUE
 *     (order, round_item).
 *
 * Además, faceta de productor: `fos_user.producer_id` (OneToOne nullable) → deriva
 * ROLE_PRODUCER, mismo patrón que partner_id/worker_id.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin al
 * desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20260702100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grupo de consumo v1: catálogo (producer/product) + rondas + fos_user.producer_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS consumer_group_producer (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(180) NOT NULL,
            contact_name VARCHAR(180) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            web VARCHAR(255) DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            minimum_note VARCHAR(255) DEFAULT NULL,
            self_managed TINYINT(1) NOT NULL,
            active TINYINT(1) NOT NULL,
            created DATETIME NOT NULL,
            updated DATETIME NOT NULL,
            INDEX idx_cg_producer_active (active),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS consumer_group_product (
            id INT AUTO_INCREMENT NOT NULL,
            producer_id INT NOT NULL,
            name VARCHAR(180) NOT NULL,
            unit VARCHAR(30) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            reference_price NUMERIC(8, 2) DEFAULT NULL,
            active TINYINT(1) NOT NULL,
            sort_order SMALLINT NOT NULL,
            INDEX idx_cg_product_producer (producer_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_cg_product_producer FOREIGN KEY (producer_id) REFERENCES consumer_group_producer (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS consumer_group_round (
            id INT AUTO_INCREMENT NOT NULL,
            producer_id INT NOT NULL,
            created_by_id INT DEFAULT NULL,
            title VARCHAR(180) NOT NULL,
            status SMALLINT NOT NULL,
            minimum_condition VARCHAR(255) DEFAULT NULL,
            description LONGTEXT DEFAULT NULL,
            orders_close_at DATETIME NOT NULL,
            delivery_date DATE NOT NULL,
            created DATETIME NOT NULL,
            updated DATETIME NOT NULL,
            INDEX idx_cg_round_status (status),
            INDEX idx_cg_round_producer (producer_id),
            INDEX idx_cg_round_created_by (created_by_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_cg_round_producer FOREIGN KEY (producer_id) REFERENCES consumer_group_producer (id) ON DELETE RESTRICT,
            CONSTRAINT FK_cg_round_created_by FOREIGN KEY (created_by_id) REFERENCES fos_user (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS consumer_group_round_item (
            id INT AUTO_INCREMENT NOT NULL,
            round_id INT NOT NULL,
            product_id INT NOT NULL,
            price NUMERIC(8, 2) NOT NULL,
            sort_order SMALLINT NOT NULL,
            UNIQUE INDEX uniq_cg_round_item_round_product (round_id, product_id),
            INDEX idx_cg_round_item_round (round_id),
            INDEX idx_cg_round_item_product (product_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_cg_round_item_round FOREIGN KEY (round_id) REFERENCES consumer_group_round (id) ON DELETE CASCADE,
            CONSTRAINT FK_cg_round_item_product FOREIGN KEY (product_id) REFERENCES consumer_group_product (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS consumer_group_order (
            id INT AUTO_INCREMENT NOT NULL,
            round_id INT NOT NULL,
            partner_id INT NOT NULL,
            created DATETIME NOT NULL,
            updated DATETIME NOT NULL,
            UNIQUE INDEX uniq_cg_order_round_partner (round_id, partner_id),
            INDEX idx_cg_order_round (round_id),
            INDEX idx_cg_order_partner (partner_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_cg_order_round FOREIGN KEY (round_id) REFERENCES consumer_group_round (id) ON DELETE CASCADE,
            CONSTRAINT FK_cg_order_partner FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS consumer_group_order_line (
            id INT AUTO_INCREMENT NOT NULL,
            order_id INT NOT NULL,
            round_item_id INT NOT NULL,
            quantity NUMERIC(8, 2) NOT NULL,
            UNIQUE INDEX uniq_cg_order_line_order_item (order_id, round_item_id),
            INDEX idx_cg_order_line_order (order_id),
            INDEX idx_cg_order_line_item (round_item_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_cg_order_line_order FOREIGN KEY (order_id) REFERENCES consumer_group_order (id) ON DELETE CASCADE,
            CONSTRAINT FK_cg_order_line_item FOREIGN KEY (round_item_id) REFERENCES consumer_group_round_item (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Faceta de productor en el User (OneToOne nullable) → deriva ROLE_PRODUCER.
        $this->addSql('ALTER TABLE fos_user ADD producer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fos_user ADD CONSTRAINT FK_fos_user_producer FOREIGN KEY (producer_id) REFERENCES consumer_group_producer (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_fos_user_producer ON fos_user (producer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fos_user DROP FOREIGN KEY FK_fos_user_producer');
        $this->addSql('DROP INDEX UNIQ_fos_user_producer ON fos_user');
        $this->addSql('ALTER TABLE fos_user DROP producer_id');
        $this->addSql('DROP TABLE IF EXISTS consumer_group_order_line');
        $this->addSql('DROP TABLE IF EXISTS consumer_group_order');
        $this->addSql('DROP TABLE IF EXISTS consumer_group_round_item');
        $this->addSql('DROP TABLE IF EXISTS consumer_group_round');
        $this->addSql('DROP TABLE IF EXISTS consumer_group_product');
        $this->addSql('DROP TABLE IF EXISTS consumer_group_producer');
    }
}
