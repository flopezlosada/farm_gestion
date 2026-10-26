<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recibos de remesa: cuándo una persona lo revisó contra la ficha de su socix y
 * decidió dejarlo como está.
 *
 * Sólo AÑADE una columna que admite nulo: va ANTES del código al desplegar, y el
 * código viejo ni la ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261028100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: recibos de remesa revisados contra la ficha';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remittance_line ADD reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remittance_line DROP reviewed_at');
    }
}
