<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Presupuesto en vigor: contra cuál se mide el día a día de un año, que puede ser una
 * revisión y no el aprobado por la asamblea.
 *
 * La columna vale 1 o NULL, nunca 0: el índice único (año, en vigor) admite muchos
 * NULL y un solo 1, y así la base impide dos en vigor el mismo año.
 *
 * Sólo AÑADE una columna que admite NULL y un índice: va ANTES del código al
 * desplegar, y el código viejo ni la ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261026220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: presupuesto en vigor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget ADD in_force TINYINT(1) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_budget_year_in_force ON budget (budget_year, in_force)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_budget_year_in_force ON budget');
        $this->addSql('ALTER TABLE budget DROP in_force');
    }
}
