<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rediseño reparto (2026-05-27): añade `partner.display_name`, el nombre de
 * reparto por el que se conoce a la familia en el listado de cestas (apodos,
 * "X y Z"), distinto del nombre legal (name + surname, usado para cobros).
 *
 * Origen del dato: columna `nombre_pdf` de
 * `docs/socios-import/reparto_definitivo.csv` (el nombre tal como aparece en
 * el PDF de reparto), que NO se persistió en la importación inicial (solo se
 * guardó `titular_legal` → name/surname). Se rellena con el modo
 * `app:import-partners-from-csv reparto_definitivo.csv --only-display-name`,
 * que cruza por `cobros_nif` y limpia el texto (quita paréntesis aclaratorios,
 * normaliza barras, aplica title case con partículas en minúscula).
 *
 * Nullable: si está vacío, `Partner::getNameForDelivery()` cae al nombre legal.
 * Los acentos que faltan en origen y los ~2 casos sucios irreductibles
 * (nombres pegados sin espacio, typos) se revisan a mano desde la ficha.
 */
final class Version20260527110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reparto: partner.display_name (nombre de reparto desde familia_operativa)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner ADD COLUMN display_name VARCHAR(255) DEFAULT NULL AFTER surname');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner DROP COLUMN display_name');
    }
}
