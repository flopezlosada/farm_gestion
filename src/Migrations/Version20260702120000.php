<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupo de consumo: estado de pago del pedido de la socia (seguimiento manual del
 * cobro por la comisión; la app no cobra).
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin.
 */
final class Version20260702120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grupo de consumo: consumer_group_order.paid + paid_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_order
            ADD paid TINYINT(1) NOT NULL DEFAULT 0,
            ADD paid_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_order DROP paid, DROP paid_at');
    }
}
