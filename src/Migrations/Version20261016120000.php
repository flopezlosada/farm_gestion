<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las entregas de cada trimestre a la gestoría: con una en pie, lo fechado en ese
 * trimestre ya no se cambia. Una fila por entrega; reabrir marca la fila, no la
 * borra.
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine, para que el
 * esquema y el mapeo no difieran en nada.
 *
 * Tabla nueva: va ANTES del código al desplegar, y el código viejo no la ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: trimestres entregados a la gestoría';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE accountant_delivery (id INT AUTO_INCREMENT NOT NULL, delivered_by_id INT DEFAULT NULL, reopened_by_id INT DEFAULT NULL, year SMALLINT NOT NULL, quarter SMALLINT NOT NULL, delivered_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', reopened_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_45908DF7BFBEE0DA (delivered_by_id), INDEX IDX_45908DF7B802F8F8 (reopened_by_id), INDEX idx_accountant_delivery_quarter (year, quarter), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE accountant_delivery ADD CONSTRAINT FK_45908DF7BFBEE0DA FOREIGN KEY (delivered_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE accountant_delivery ADD CONSTRAINT FK_45908DF7B802F8F8 FOREIGN KEY (reopened_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE accountant_delivery');
    }
}
