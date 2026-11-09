<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupo de consumo: el pedido pasa a ser de la FAMILIA. Los pedidos que hizo un
 * miembro que no encabeza su familia se pasan a nombre de quien la encabeza, que es
 * como los busca y los guarda el código nuevo; si no, ese miembro dejaría de ver su
 * propio pedido.
 *
 * Si quien encabeza ya tenía pedido en esa misma ronda no se toca ninguno (el único
 * por ronda y socia lo impediría y fundirlos es cosa de la comisión): se quedan a la
 * vista en gestión para que decida.
 *
 * No cambia el esquema. Va JUSTO DESPUÉS del código: antes, el código viejo seguiría
 * guardando pedidos a nombre de cada miembro.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261109180000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Grupo de consumo: los pedidos de un miembro pasan a quien encabeza su familia';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE consumer_group_order o
            JOIN partner p ON p.id = o.partner_id
            LEFT JOIN consumer_group_order h ON h.round_id = o.round_id AND h.partner_id = p.parent_id
            SET o.partner_id = p.parent_id
            WHERE p.parent_id IS NOT NULL AND h.id IS NULL');
    }

    /**
     * No se puede deshacer: tras el traspaso no queda rastro de qué miembro hizo cada
     * pedido, y el código viejo los vería igual (a nombre de quien encabeza).
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
