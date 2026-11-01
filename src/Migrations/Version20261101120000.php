<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Desde cuándo se lleva el registro de jornada de cada trabajador en la app: los
 * días entre el alta y esa fecha dejan de salir como «sin fichar».
 *
 * Sólo AÑADE una columna que admite NULL: va ANTES del código al desplegar, y el
 * código viejo ni la ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trabajadores: fecha de inicio del registro de jornada en la app';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker ADD recording_start_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker DROP recording_start_date');
    }
}
