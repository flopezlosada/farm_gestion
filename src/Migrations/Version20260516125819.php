<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bloque 2: modelo de datos nuevo para reparto.
 *
 * - Crea delivery_exception: excepciones al calendario de reparto del viernes
 *   (sin reparto, adelantos a jueves/miércoles, fechas alternativas).
 * - Crea partner_event: histórico inmutable de eventos de negocio del socio
 *   (alta, baja, pausa, reanudación, cesta, cambios de grupo A/B, cambios de
 *   nodo). Alimenta estadística y feed de actividad.
 * - Añade partner_basket_share.delivery_group (A/B/null) para el balanceo
 *   semanal de cestas en quincenales y mensuales.
 *
 * Esta migración cubre SOLO el delta de este bloque. El drift previo
 * (fos_user.partner_id) se gestionará por separado.
 */
final class Version20260516125819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bloque 2: delivery_exception, partner_event, partner_basket_share.delivery_group';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on \'mysql\'.'
        );

        $this->addSql('CREATE TABLE delivery_exception ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'friday_date DATE NOT NULL, '
            . 'shifted_date DATE DEFAULT NULL, '
            . 'notes LONGTEXT DEFAULT NULL, '
            . 'created DATETIME NOT NULL, '
            . 'updated DATETIME NOT NULL, '
            . 'UNIQUE INDEX UNIQ_3BACC2FFB54E8AA8 (friday_date), '
            . 'PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE partner_event ('
            . 'id INT AUTO_INCREMENT NOT NULL, '
            . 'partner_id INT NOT NULL, '
            . 'type VARCHAR(40) NOT NULL, '
            . 'occurred_at DATETIME NOT NULL, '
            . 'payload JSON DEFAULT NULL, '
            . 'notes LONGTEXT DEFAULT NULL, '
            . 'actor VARCHAR(80) DEFAULT NULL, '
            . 'created DATETIME NOT NULL, '
            . 'INDEX IDX_2892A6C29393F8FE (partner_id), '
            . 'INDEX idx_partner_event_partner_occurred (partner_id, occurred_at), '
            . 'INDEX idx_partner_event_type_occurred (type, occurred_at), '
            . 'PRIMARY KEY(id)'
            . ') DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE partner_event '
            . 'ADD CONSTRAINT FK_2892A6C29393F8FE '
            . 'FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE partner_basket_share ADD delivery_group VARCHAR(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on \'mysql\'.'
        );

        $this->addSql('ALTER TABLE partner_basket_share DROP delivery_group');
        $this->addSql('ALTER TABLE partner_event DROP FOREIGN KEY FK_2892A6C29393F8FE');
        $this->addSql('DROP TABLE partner_event');
        $this->addSql('DROP TABLE delivery_exception');
    }
}
