<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aprobación mensual del registro de jornada: una fila por aprobación; reabrir
 * marca la fila, no la borra. Con una vigente, el mes de ese trabajador no se
 * cambia.
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine, para que el
 * esquema y el mapeo no difieran en nada.
 *
 * Tabla nueva: va ANTES del código al desplegar, y el código viejo no la ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261020120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Laboral: aprobación mensual del registro de jornada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE work_month_close (id INT AUTO_INCREMENT NOT NULL, worker_id INT NOT NULL, approved_by_id INT DEFAULT NULL, reopened_by_id INT DEFAULT NULL, year SMALLINT NOT NULL, month SMALLINT NOT NULL, approved_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', reopened_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_E3F3DEC86B20BA36 (worker_id), INDEX IDX_E3F3DEC82D234F6A (approved_by_id), INDEX IDX_E3F3DEC8B802F8F8 (reopened_by_id), INDEX idx_work_month_close_month (worker_id, year, month), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE work_month_close ADD CONSTRAINT FK_E3F3DEC86B20BA36 FOREIGN KEY (worker_id) REFERENCES worker (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE work_month_close ADD CONSTRAINT FK_E3F3DEC82D234F6A FOREIGN KEY (approved_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE work_month_close ADD CONSTRAINT FK_E3F3DEC8B802F8F8 FOREIGN KEY (reopened_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE work_month_close');
    }
}
