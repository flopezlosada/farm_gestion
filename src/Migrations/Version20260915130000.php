<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `consumer_group_order.picked_up` / `picked_up_at`: marca de recogida del
 * pedido del grupo de consumo, igual que la de pago. Ni la socia ni la
 * comisión tenían dónde apuntar que el producto ya se había recogido en el
 * nodo.
 */
final class Version20260915130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'consumer_group_order: picked_up y picked_up_at (marca de recogida)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE consumer_group_order ADD picked_up TINYINT(1) DEFAULT 0 NOT NULL COMMENT 'Si la socia ha recogido este pedido'");
        $this->addSql("ALTER TABLE consumer_group_order ADD picked_up_at DATETIME DEFAULT NULL COMMENT 'Cuándo se marcó como recogido'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_order DROP picked_up_at');
        $this->addSql('ALTER TABLE consumer_group_order DROP picked_up');
    }
}
