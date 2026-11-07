<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupo de consumo: el mínimo del productor pasa a ser una elección obligatoria
 * entre cuatro (0 sin mínimo, 1 importe, 2 nº de socias, 3 otro, a mano).
 *
 * Hasta ahora null significaba «se confirma a mano», así que los pedidos que ya
 * existen con null pasan a 3: siguen exactamente como estaban. Ninguno pasa a
 * «sin mínimo» por su cuenta, porque eso los confirmaría.
 *
 * Va JUSTO ANTES del código: el código nuevo no admite null en la columna y el
 * viejo inserta null al crear un pedido sin mínimo automático. Entre una cosa y
 * otra, crear un pedido falla; leerlos, no.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261107120000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Grupo de consumo: el tipo de mínimo es obligatorio (null pasa a «otro, a mano»)';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE consumer_group_round SET minimum_type = 3 WHERE minimum_type IS NULL');
        $this->addSql("ALTER TABLE consumer_group_round MODIFY minimum_type SMALLINT NOT NULL COMMENT 'Tipo de mínimo: 0=sin mínimo, 1=importe €, 2=nº socias con pedido, 3=otro (a mano)'");
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE consumer_group_round MODIFY minimum_type SMALLINT DEFAULT NULL COMMENT 'Tipo de mínimo: 1=importe €, 2=nº socias con pedido; NULL=sin mínimo automatizable'");
        $this->addSql('UPDATE consumer_group_round SET minimum_type = NULL WHERE minimum_type IN (0, 3)');
    }
}
