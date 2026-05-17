<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crea partner_delivery_shift: cambio puntual de viernes de un socio
 * (recoge en otro Basket en vez del que le tocaba por su pauta normal).
 *
 * Esta entidad es la fuente de verdad del intercambio. Las consecuencias
 * sobre weekly_basket se aplican en cascada desde el servicio aplicador,
 * no por triggers de BBDD.
 *
 * Restricciones únicas (partner, from_basket) y (partner, to_basket):
 * un socio no puede tener dos shifts saliendo del mismo viernes ni dos
 * shifts entrando al mismo viernes — invariantes del modelo.
 */
final class Version20260517100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'partner_delivery_shift: cambio puntual de viernes por socio';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on \'mysql\'.'
        );

        $this->addSql('CREATE TABLE partner_delivery_shift ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'partner_id INT NOT NULL, '
            . 'from_basket_id INT NOT NULL, '
            . 'to_basket_id INT NOT NULL, '
            . 'notes LONGTEXT DEFAULT NULL, '
            . 'created DATETIME NOT NULL, '
            . 'updated DATETIME NOT NULL, '
            . 'UNIQUE INDEX uniq_partner_from_basket (partner_id, from_basket_id), '
            . 'UNIQUE INDEX uniq_partner_to_basket (partner_id, to_basket_id), '
            . 'INDEX idx_from_basket (from_basket_id), '
            . 'INDEX idx_to_basket (to_basket_id), '
            . 'PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE partner_delivery_shift '
            . 'ADD CONSTRAINT FK_PDS_PARTNER '
            . 'FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE partner_delivery_shift '
            . 'ADD CONSTRAINT FK_PDS_FROM_BASKET '
            . 'FOREIGN KEY (from_basket_id) REFERENCES basket (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE partner_delivery_shift '
            . 'ADD CONSTRAINT FK_PDS_TO_BASKET '
            . 'FOREIGN KEY (to_basket_id) REFERENCES basket (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on \'mysql\'.'
        );

        $this->addSql('ALTER TABLE partner_delivery_shift DROP FOREIGN KEY FK_PDS_TO_BASKET');
        $this->addSql('ALTER TABLE partner_delivery_shift DROP FOREIGN KEY FK_PDS_FROM_BASKET');
        $this->addSql('ALTER TABLE partner_delivery_shift DROP FOREIGN KEY FK_PDS_PARTNER');
        $this->addSql('DROP TABLE partner_delivery_shift');
    }
}
