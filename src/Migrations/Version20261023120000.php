<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baja médica abierta: `absence.end_date` admite NULL mientras no llega el parte
 * de alta. Sólo la baja puede quedar así (lo valida la entidad).
 *
 * Relajar un NOT NULL: va ANTES del código al desplegar; el código viejo sigue
 * escribiendo siempre fecha de fin y no nota nada.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Laboral: baja médica sin fecha de alta (absence.end_date nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE absence CHANGE end_date end_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE absence CHANGE end_date end_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)'");
    }
}
