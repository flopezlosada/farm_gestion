<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rediseño del calendario de recogida — Etapa 1 (2026-05-29): modelo de
 * composición de la entrega.
 *
 * Introduce:
 *   - `basket_component`: catálogo de componentes de una entrega. Seed con ids
 *     FIJOS — Verdura=1, Huevos=2 — porque el generador los referencia por
 *     constante (BasketComponent::ID_VEGETABLES / ID_EGGS).
 *   - `weekly_basket_item`: línea materializada (entrega → componente → cantidad).
 *     FK a weekly_basket con ON DELETE CASCADE: los ítems viajan/desaparecen con
 *     su entrega al moverla o borrarla.
 *
 * Migración inerte en esta etapa: las tablas se rellenan al generar pero todavía
 * no las lee nadie (write-only). No cambia el comportamiento del listado/PDF.
 *
 * Idempotente: CREATE TABLE IF NOT EXISTS + INSERT IGNORE.
 *
 * Recordatorio operativo: aplicar también a db_prod_snapshot y a csastaging
 * (ver memoria schema-changes-both-dbs; staging vía phpMyAdmin al desplegar).
 */
final class Version20260529120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendario recogida Etapa 1: basket_component (seed Verdura/Huevos) + weekly_basket_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS basket_component (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            UNIQUE INDEX UNIQ_basket_component_name (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS weekly_basket_item (
            id INT AUTO_INCREMENT NOT NULL,
            weekly_basket_id INT NOT NULL,
            basket_component_id INT NOT NULL,
            amount NUMERIC(6, 2) NOT NULL,
            INDEX idx_wbi_weekly_basket (weekly_basket_id),
            INDEX idx_wbi_component (basket_component_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_wbi_weekly_basket FOREIGN KEY (weekly_basket_id) REFERENCES weekly_basket (id) ON DELETE CASCADE,
            CONSTRAINT FK_wbi_component FOREIGN KEY (basket_component_id) REFERENCES basket_component (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("INSERT IGNORE INTO basket_component (id, name) VALUES
            (1, 'Verdura'),
            (2, 'Huevos')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS weekly_basket_item');
        $this->addSql('DROP TABLE IF EXISTS basket_component');
    }
}
