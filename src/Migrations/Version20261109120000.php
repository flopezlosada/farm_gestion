<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Socixs: cómo paga cada ficha (recibo, transferencia o no paga) y el motivo, para
 * que la remesa no cuente como «sin recibo» a quien paga de otra forma.
 *
 * Va ANTES del código: el código viejo no mapea las columnas y el valor por defecto
 * («receipt», recibo) es lo que todas son hoy. Después, en la ficha de cada una que
 * no paga por recibo se elige cómo paga.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261109120000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Socixs: cómo paga (recibo, transferencia, no paga) y motivo';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE partner ADD payment_method VARCHAR(10) DEFAULT 'receipt' NOT NULL, ADD payment_note VARCHAR(255) DEFAULT NULL");
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner DROP payment_method, DROP payment_note');
    }
}
