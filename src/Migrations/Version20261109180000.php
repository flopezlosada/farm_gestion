<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupo de consumo: cómo se pagó cada pedido (recibo, transferencia o metálico) y en
 * qué recibo de la remesa del grupo de consumo va.
 *
 * `paid_via` sustituye a `paid`: null = pendiente. Los pedidos ya pagados pasan a
 * «transferencia», que es como se pagaban hasta ahora (no había otra forma prevista).
 * `paid` se queda sin mapear (tiene DEFAULT 0, así que el código nuevo inserta sin
 * tocarla) y se quita en una migración aparte, cuando este código esté en producción.
 *
 * Va ANTES del código: el código viejo no mapea las columnas nuevas.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261109180000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Grupo de consumo: forma de pago del pedido y su recibo de remesa';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_order ADD paid_via VARCHAR(10) DEFAULT NULL, ADD remittance_line_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE consumer_group_order ADD CONSTRAINT FK_CG_ORDER_REMITTANCE_LINE FOREIGN KEY (remittance_line_id) REFERENCES remittance_line (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CG_ORDER_REMITTANCE_LINE ON consumer_group_order (remittance_line_id)');
        $this->addSql("UPDATE consumer_group_order SET paid_via = 'transfer' WHERE paid = 1");
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE consumer_group_order SET paid = (paid_via IS NOT NULL)');
        $this->addSql('ALTER TABLE consumer_group_order DROP FOREIGN KEY FK_CG_ORDER_REMITTANCE_LINE');
        $this->addSql('DROP INDEX IDX_CG_ORDER_REMITTANCE_LINE ON consumer_group_order');
        $this->addSql('ALTER TABLE consumer_group_order DROP paid_via, DROP remittance_line_id');
    }
}
