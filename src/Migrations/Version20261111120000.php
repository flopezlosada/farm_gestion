<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupo de consumo: marca de «el banco devolvió el recibo de este pedido».
 *
 * Sin ella, un pedido devuelto se ve como «va en el recibo», que ya no es verdad.
 * Los pedidos de remesas ya enviadas los recalcula el código al cruzar el extracto,
 * así que no hace falta tocar datos.
 *
 * Va ANTES del código: el código nuevo ya mapea la columna.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261111120000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Grupo de consumo: consumer_group_order.receipt_returned';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_order ADD receipt_returned TINYINT(1) DEFAULT 0 NOT NULL');
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_order DROP receipt_returned');
    }
}
