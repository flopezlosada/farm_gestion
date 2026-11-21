<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupo de consumo: precio desglosado en precio del productor + incremento de gestión.
 *
 * `consumer_group_round` guarda el incremento por defecto del pedido (tipo + valor) y
 * `consumer_group_round_item` el precio del productor y, si lo tiene, un incremento
 * propio que sustituye al del pedido. `markup_pending_review` marca los productos con
 * incremento propio cuando cambia el del pedido: hasta que se validan no se puede abrir.
 * El precio final sigue siendo `price`; las filas existentes quedan sin desglosar (NULL).
 *
 * Va ANTES del código: el código nuevo ya mapea las columnas.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261121100000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Grupo de consumo: precio del productor e incremento de gestión';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_round ADD markup_type VARCHAR(10) DEFAULT NULL, ADD markup_value NUMERIC(8, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE consumer_group_round_item ADD producer_price NUMERIC(8, 2) DEFAULT NULL, ADD markup_type VARCHAR(10) DEFAULT NULL, ADD markup_value NUMERIC(8, 2) DEFAULT NULL, ADD markup_pending_review TINYINT(1) DEFAULT 0 NOT NULL');
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_round DROP markup_type, DROP markup_value');
        $this->addSql('ALTER TABLE consumer_group_round_item DROP producer_price, DROP markup_type, DROP markup_value, DROP markup_pending_review');
    }
}
