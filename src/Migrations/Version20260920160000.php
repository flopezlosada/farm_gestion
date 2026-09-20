<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `work_schedule`: horario habitual por día de la semana (tramos en JSON),
 * global (worker_id NULL) o excepción de un trabajador concreto. Es la
 * plantilla que consulta el botón "rellenar con mi horario habitual" del panel
 * de fichaje.
 *
 * Sin UNIQUE (worker_id, day_of_week): con worker_id nullable, MySQL no evita
 * dos filas globales del mismo día (NULL no colisiona consigo mismo). La
 * integridad la da el flujo de guardado del admin (find-or-create sobre las 7
 * filas de la semana), no una constraint de BBDD.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20260920160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'work_schedule: horario habitual por día de semana, global o por trabajador';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS work_schedule (
                id INT AUTO_INCREMENT NOT NULL,
                worker_id INT DEFAULT NULL,
                day_of_week INT NOT NULL COMMENT 'ISO-8601: 1 lunes .. 7 domingo',
                times JSON DEFAULT NULL COMMENT 'Tramos [inicio, fin] en HH:MM; vacío = no se trabaja ese día',
                INDEX IDX_WORK_SCHEDULE_WORKER (worker_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE work_schedule ADD CONSTRAINT FK_WORK_SCHEDULE_WORKER FOREIGN KEY (worker_id) REFERENCES worker (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE work_schedule');
    }
}
