<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupo de consumo — enriquecimiento del catálogo y la ronda (2026-07-02):
 *   - `consumer_group_category`: categorías de producto (verdura, fruta…).
 *   - `consumer_group_product`: + category_id (FK SET NULL) + image.
 *   - `consumer_group_round`: + provider_note, cancel_reason y las fechas de cada
 *     paso (closed_at, confirmed_at, delivered_at, cancelled_at).
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin.
 */
final class Version20260702110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grupo de consumo: categorías + imagen de producto + comentarios/fechas de ronda';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS consumer_group_category (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(120) NOT NULL,
            sort_order SMALLINT NOT NULL,
            active TINYINT(1) NOT NULL,
            UNIQUE INDEX uniq_cg_category_name (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE consumer_group_product
            ADD category_id INT DEFAULT NULL,
            ADD image VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE consumer_group_product
            ADD CONSTRAINT FK_cg_product_category FOREIGN KEY (category_id) REFERENCES consumer_group_category (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_cg_product_category ON consumer_group_product (category_id)');

        $this->addSql('ALTER TABLE consumer_group_round
            ADD provider_note LONGTEXT DEFAULT NULL,
            ADD cancel_reason LONGTEXT DEFAULT NULL,
            ADD closed_at DATETIME DEFAULT NULL,
            ADD confirmed_at DATETIME DEFAULT NULL,
            ADD delivered_at DATETIME DEFAULT NULL,
            ADD cancelled_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_round
            DROP provider_note, DROP cancel_reason, DROP closed_at, DROP confirmed_at, DROP delivered_at, DROP cancelled_at');
        $this->addSql('ALTER TABLE consumer_group_product DROP FOREIGN KEY FK_cg_product_category');
        $this->addSql('DROP INDEX idx_cg_product_category ON consumer_group_product');
        $this->addSql('ALTER TABLE consumer_group_product DROP category_id, DROP image');
        $this->addSql('DROP TABLE IF EXISTS consumer_group_category');
    }
}
