<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tarjetas: el concepto con que el banco cobra la liquidación, para que ese cargo se
 * anote solo como traspaso del banco a la tarjeta al subir el extracto.
 *
 * Va ANTES del código: el código viejo no mapea la columna y una nula no cambia
 * nada. Después, en la ficha de la tarjeta se escribe el concepto.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261108090000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Tarjetas: concepto de la liquidación en el banco';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_account ADD settlement_concept VARCHAR(255) DEFAULT NULL');
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_account DROP settlement_concept');
    }
}
