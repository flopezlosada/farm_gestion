<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El proveedor de un apunte de gasto, como ficha y no sólo como texto: así sus pagos
 * (con factura o sin ella) salen juntos en la ficha del proveedor.
 *
 * Sólo AÑADE una columna que admite NULL, su índice y su clave ajena: va ANTES del
 * código al desplegar. Los apuntes que ya hay se quedan con su texto; los nombres de
 * los gastos se casan con su ficha a mano, aparte.
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261102180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Proveedor (ficha) del apunte de gasto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_entry ADD provider_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE account_entry ADD CONSTRAINT FK_1DAE4401A53A8AA FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1DAE4401A53A8AA ON account_entry (provider_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_entry DROP FOREIGN KEY FK_1DAE4401A53A8AA');
        $this->addSql('DROP INDEX IDX_1DAE4401A53A8AA ON account_entry');
        $this->addSql('ALTER TABLE account_entry DROP provider_id');
    }
}
