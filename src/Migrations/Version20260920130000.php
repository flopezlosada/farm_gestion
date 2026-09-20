<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La unidad de venta de un producto pasa de texto libre por producto a una
 * tabla GLOBAL ({@see \App\Entity\ConsumerGroupUnit}): la comisión quiere
 * poder comparar/sumar cantidades entre productos distintos ("cuántos kilos
 * se compraron en 2020"), y eso exige que "kg" sea siempre la misma fila, no
 * un texto repetido —y a veces mal escrito— en cada producto.
 *
 * Backfill: una fila de consumer_group_unit por cada valor DISTINCT ya usado
 * (recortado de espacios; la collation utf8mb4 de la app es case-insensitive,
 * así que "Kg"/"kg" ya colapsan solos en el DISTINCT), enlazado después por
 * nombre. `unit_id` es RESTRICT: una unidad en uso no se puede borrar, se
 * marca inactiva.
 */
final class Version20260920130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'consumer_group_product.unit: de texto libre por producto a consumer_group_unit (tabla global)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE consumer_group_unit (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(60) NOT NULL,
                sort_order SMALLINT NOT NULL,
                active TINYINT(1) NOT NULL,
                UNIQUE INDEX UNIQ_cg_unit_name (name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO consumer_group_unit (name, sort_order, active)
            SELECT DISTINCT TRIM(unit), 0, 1
            FROM consumer_group_product
            WHERE unit IS NOT NULL AND TRIM(unit) != ''
            SQL);

        $this->addSql('ALTER TABLE consumer_group_product ADD unit_id INT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE consumer_group_product p
            JOIN consumer_group_unit u ON TRIM(p.unit) = u.name
            SET p.unit_id = u.id
            SQL);

        $this->addSql('ALTER TABLE consumer_group_product MODIFY unit_id INT NOT NULL');
        $this->addSql('ALTER TABLE consumer_group_product DROP unit');
        $this->addSql('ALTER TABLE consumer_group_product ADD CONSTRAINT FK_cg_product_unit FOREIGN KEY (unit_id) REFERENCES consumer_group_unit (id)');
        $this->addSql('CREATE INDEX IDX_cg_product_unit ON consumer_group_product (unit_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_product DROP FOREIGN KEY FK_cg_product_unit');
        $this->addSql('DROP INDEX IDX_cg_product_unit ON consumer_group_product');
        $this->addSql('ALTER TABLE consumer_group_product ADD unit VARCHAR(30) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE consumer_group_product p
            JOIN consumer_group_unit u ON p.unit_id = u.id
            SET p.unit = u.name
            SQL);
        $this->addSql('ALTER TABLE consumer_group_product MODIFY unit VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE consumer_group_product DROP unit_id');
        $this->addSql('DROP TABLE consumer_group_unit');
    }
}
