<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tipo de jornada de cada trabajador (completa / tiempo parcial): decide si se le
 * manda el resumen del mes.
 *
 * Nace NULL a propósito para los trabajadores que ya existen: no se sabe qué
 * contrato tienen, y la ficha pide completarlo.
 *
 * Columna nueva: va ANTES del código al desplegar, y el código viejo no la ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Laboral: tipo de jornada del trabajador';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker ADD workday_type VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker DROP workday_type');
    }
}
