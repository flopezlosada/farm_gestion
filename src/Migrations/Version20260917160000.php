<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `consumer_group_round.minimum_type` / `minimum_value`: condición de mínimo
 * ESTRUCTURADA, además del texto libre (`minimum_condition`) que ya existía.
 * Solo con un valor numérico y un tipo la app puede comparar contra el
 * agregado de la ronda y confirmar sola; el texto libre se queda para
 * mínimos que no encajan en ninguno de los dos tipos soportados (importe
 * total o nº de socias con pedido).
 */
final class Version20260917160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'consumer_group_round: minimum_type y minimum_value (mínimo estructurado, para auto-confirmar)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE consumer_group_round ADD minimum_type SMALLINT DEFAULT NULL COMMENT 'Tipo de mínimo: 1=importe €, 2=nº socias con pedido; NULL=sin mínimo automatizable'");
        $this->addSql("ALTER TABLE consumer_group_round ADD minimum_value NUMERIC(8, 2) DEFAULT NULL COMMENT 'Umbral del mínimo, en la unidad de minimum_type'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_round DROP minimum_value');
        $this->addSql('ALTER TABLE consumer_group_round DROP minimum_type');
    }
}
