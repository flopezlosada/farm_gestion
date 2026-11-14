<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baja médica: marca de «la asociación complementa hasta el 100 %».
 *
 * Marcada, la persona cobra el sueldo entero durante la baja; desmarcada, sólo la
 * prestación. Cambia lo que cuesta la baja en la previsión de sueldos. Nace a 1 (la
 * decisión por defecto), también para las bajas que ya existen.
 *
 * Va ANTES del código: el código nuevo ya mapea la columna.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261114200000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Baja médica: absence.tops_up_to_full_pay';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE absence ADD tops_up_to_full_pay TINYINT(1) DEFAULT 1 NOT NULL');
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE absence DROP tops_up_to_full_pay');
    }
}
