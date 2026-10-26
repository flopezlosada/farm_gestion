<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pago agrupado: varias facturas recibidas pueden ir al mismo apunte, porque un
 * proveedor cobra de una vez lo de varias. `received_invoice.account_entry_id` deja
 * de ser único (sigue indexado: lo pide la clave ajena).
 *
 * Relajar un UNIQUE: va ANTES del código al desplegar; el código viejo sigue
 * poniendo una factura por apunte y no nota nada. El índice nuevo se crea antes de
 * borrar el viejo porque MySQL no deja la clave ajena sin índice.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: varias facturas recibidas por apunte (received_invoice.account_entry_id sin UNIQUE)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_32861D1DC2F8ABF4 ON received_invoice (account_entry_id)');
        $this->addSql('DROP INDEX UNIQ_32861D1DC2F8ABF4 ON received_invoice');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_32861D1DC2F8ABF4 ON received_invoice (account_entry_id)');
        $this->addSql('DROP INDEX IDX_32861D1DC2F8ABF4 ON received_invoice');
    }
}
