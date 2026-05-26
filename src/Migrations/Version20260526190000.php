<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sub-fase 8.8a (2026-05-26): introduce la entidad `Node` (sitio físico de
 * reparto) y la FK `weekly_basket_group.node_id`.
 *
 * Tres nodos sembrados según la operativa actual:
 *   - Torremocha: viernes (ISO 5), semanal, sin ancla.
 *   - Cascorro:   miércoles (ISO 3), quincenal, ancla 2026-05-06.
 *   - Midori:     miércoles (ISO 3), quincenal, ancla 2026-05-06.
 *
 * Los WeeklyBasketGroup existentes se asignan automáticamente:
 *   - Cascorro y Midori (WBG con esos nombres) cuelgan de sus nodos
 *     homónimos.
 *   - El resto (Torremocha, La Cabrera, Patones, Pedrezuela, Trabensol...)
 *     cuelgan del nodo Torremocha por defecto.
 *
 * Migración inerte: sólo añade estructura y datos catalogados. La lógica
 * Node-aware (DeadlineRule, BiweeklyCohortResolver, WeeklyBasket.delivery_date)
 * se incorpora en sub-fase 8.8b.
 *
 * Idempotente para CREATE/INSERT: en re-ejecución sobre BBDD con el schema
 * ya aplicado, los INSERT fallarán por UNIQUE pero las dos primeras
 * sentencias DDL están protegidas con IF NOT EXISTS.
 */
final class Version20260526190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sub-fase 8.8a: entidad Node + FK weekly_basket_group.node_id + seed Torremocha/Cascorro/Midori';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS node (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            delivery_weekday SMALLINT NOT NULL,
            cadence VARCHAR(16) NOT NULL,
            anchor_date DATE DEFAULT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_node_name (name)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE weekly_basket_group
            ADD COLUMN node_id INT DEFAULT NULL,
            ADD CONSTRAINT FK_wbg_node FOREIGN KEY (node_id) REFERENCES node (id),
            ADD INDEX IDX_wbg_node (node_id)');

        $this->addSql("INSERT INTO node (name, delivery_weekday, cadence, anchor_date) VALUES
            ('Torremocha', 5, 'weekly',   NULL),
            ('Cascorro',   3, 'biweekly', '2026-05-06'),
            ('Midori',     3, 'biweekly', '2026-05-06')");

        $this->addSql("UPDATE weekly_basket_group SET node_id = (SELECT id FROM node WHERE name = 'Torremocha')");

        $this->addSql("UPDATE weekly_basket_group w SET node_id = (SELECT id FROM node WHERE name = w.name) WHERE w.name IN ('Cascorro', 'Midori')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE weekly_basket_group DROP FOREIGN KEY FK_wbg_node');
        $this->addSql('ALTER TABLE weekly_basket_group DROP INDEX IDX_wbg_node');
        $this->addSql('ALTER TABLE weekly_basket_group DROP COLUMN node_id');
        $this->addSql('DROP TABLE node');
    }
}
