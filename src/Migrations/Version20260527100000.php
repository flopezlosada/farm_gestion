<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sub-fase 8.8d (2026-05-27): reescribe `delivery_exception` para anclarla
 * a (basket, node) en vez de a `friday_date`.
 *
 * Motivo: la excepción de calendario nació cuando todo el reparto caía en
 * viernes, así que una fecha suelta global la identificaba. Con la entrada
 * de nodos en días distintos (Madrid en miércoles) eso dejó de valer: un
 * festivo de viernes afecta a Torremocha pero no a Cascorro. Ahora la
 * excepción apunta a un ciclo (basket_id) y opcionalmente a un nodo
 * (node_id nullable; null = todos los nodos, p.ej. Navidad).
 *
 * La tabla está vacía en prod (eran pruebas históricas, 0 filas), por lo
 * que se recrean las columnas sin migrar datos.
 */
final class Version20260527100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sub-fase 8.8d: delivery_exception anclada a (basket, node) en vez de friday_date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_exception DROP INDEX UNIQ_3BACC2FFB54E8AA8');
        $this->addSql('ALTER TABLE delivery_exception DROP COLUMN friday_date');

        $this->addSql('ALTER TABLE delivery_exception ADD COLUMN basket_id INT NOT NULL');
        $this->addSql('ALTER TABLE delivery_exception ADD COLUMN node_id INT DEFAULT NULL');

        $this->addSql('ALTER TABLE delivery_exception ADD CONSTRAINT FK_de_basket FOREIGN KEY (basket_id) REFERENCES basket (id)');
        $this->addSql('ALTER TABLE delivery_exception ADD CONSTRAINT FK_de_node FOREIGN KEY (node_id) REFERENCES node (id) ON DELETE CASCADE');

        $this->addSql('CREATE INDEX idx_de_basket ON delivery_exception (basket_id)');
        $this->addSql('CREATE INDEX idx_de_node ON delivery_exception (node_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_exception DROP FOREIGN KEY FK_de_basket');
        $this->addSql('ALTER TABLE delivery_exception DROP FOREIGN KEY FK_de_node');
        $this->addSql('DROP INDEX idx_de_basket ON delivery_exception');
        $this->addSql('DROP INDEX idx_de_node ON delivery_exception');
        $this->addSql('ALTER TABLE delivery_exception DROP COLUMN basket_id');
        $this->addSql('ALTER TABLE delivery_exception DROP COLUMN node_id');

        $this->addSql('ALTER TABLE delivery_exception ADD COLUMN friday_date DATE NOT NULL');
        $this->addSql('ALTER TABLE delivery_exception ADD UNIQUE INDEX UNIQ_3BACC2FFB54E8AA8 (friday_date)');
    }
}
