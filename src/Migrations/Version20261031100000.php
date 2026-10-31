<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contabilidad: los avisos de notificación electrónica oficial (DEHú, Agencia
 * Tributaria, Seguridad Social) que llegan al buzón de facturas, hasta que alguien
 * los marca como revisados en la sede.
 *
 * Sólo CREA una tabla nueva: va ANTES del código al desplegar, y el código viejo ni
 * la ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: avisos de notificación oficial llegados al buzón';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE official_notice (id INT AUTO_INCREMENT NOT NULL, reviewed_by_id INT DEFAULT NULL, message_key CHAR(64) NOT NULL, agency VARCHAR(80) NOT NULL, sender VARCHAR(255) NOT NULL, received_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', reviewed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_AA5D90B3A3A52145 (message_key), INDEX IDX_AA5D90B3FC6B21F1 (reviewed_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE official_notice ADD CONSTRAINT FK_AA5D90B3FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE official_notice');
    }
}
