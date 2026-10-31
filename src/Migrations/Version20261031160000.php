<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remesas: el día de cargo que se pidió al banco al enviarla, para avisar si el
 * abono no entra en el libro.
 *
 * Sólo AÑADE una columna que admite nulo: va ANTES del código al desplegar, y el
 * código viejo ni la ve. Las remesas que ya hay quedan sin fecha y no se vigilan.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261031160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: día de cargo pedido de cada remesa';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remittance ADD charge_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE remittance DROP charge_date');
    }
}
