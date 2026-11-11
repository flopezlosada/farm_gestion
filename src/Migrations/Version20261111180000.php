<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupo de consumo: pagar pedidos con las transferencias del extracto.
 *
 * - consumer_group_transfer: lo decidido sobre cada ingreso del banco (qué pedidos
 *   paga, o que no paga ninguno), para que el cruce automático no rehaga lo que una
 *   persona deshizo.
 * - consumer_group_order.transfer_id: con qué movimiento se pagó el pedido.
 * - bank_payer_alias: ordenante del banco → familia, aprendido al asignar a mano.
 *
 * Sin datos que tocar. Va ANTES del código: el código nuevo ya mapea las tablas.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261111180000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Grupo de consumo: consumer_group_transfer, consumer_group_order.transfer_id, bank_payer_alias';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE consumer_group_transfer (id INT AUTO_INCREMENT NOT NULL, bank_line_id INT NOT NULL, dismissed TINYINT(1) DEFAULT 0 NOT NULL, automatic TINYINT(1) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_82AB64E663EF1DEC (bank_line_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE consumer_group_transfer ADD CONSTRAINT FK_82AB64E663EF1DEC FOREIGN KEY (bank_line_id) REFERENCES bank_statement_line (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE consumer_group_order ADD transfer_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_CG_ORDER_TRANSFER ON consumer_group_order (transfer_id)');
        $this->addSql('ALTER TABLE consumer_group_order ADD CONSTRAINT FK_2717D883537048AF FOREIGN KEY (transfer_id) REFERENCES consumer_group_transfer (id) ON DELETE SET NULL');
        $this->addSql('CREATE TABLE bank_payer_alias (id INT AUTO_INCREMENT NOT NULL, partner_id INT NOT NULL, payer_key VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_EC6106D64E8FA2AF (payer_key), INDEX IDX_EC6106D69393F8FE (partner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE bank_payer_alias ADD CONSTRAINT FK_EC6106D69393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE');
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_order DROP FOREIGN KEY FK_2717D883537048AF');
        $this->addSql('DROP INDEX IDX_CG_ORDER_TRANSFER ON consumer_group_order');
        $this->addSql('ALTER TABLE consumer_group_order DROP transfer_id');
        $this->addSql('DROP TABLE consumer_group_transfer');
        $this->addSql('DROP TABLE bank_payer_alias');
    }
}
