<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sueldos de los trabajadores: tramos de bruto anual, nº de pagas y % de Seguridad
 * Social de la empresa, para prever lo que cuestan cada mes.
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine, para que el
 * esquema y el mapeo no difieran en nada.
 *
 * Sólo CREA una tabla nueva: va ANTES del código al desplegar, y el código viejo ni
 * la ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261026200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: sueldos de los trabajadores';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE worker_salary (id INT AUTO_INCREMENT NOT NULL, worker_id INT NOT NULL, valid_from DATE NOT NULL COMMENT '(DC2Type:date_immutable)', valid_to DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', annual_gross NUMERIC(10, 2) NOT NULL, payments SMALLINT NOT NULL, employer_ss_rate NUMERIC(5, 2) NOT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, INDEX IDX_15B10A186B20BA36 (worker_id), UNIQUE INDEX worker_salary_start (worker_id, valid_from), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE worker_salary ADD CONSTRAINT FK_15B10A186B20BA36 FOREIGN KEY (worker_id) REFERENCES worker (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE worker_salary');
    }
}
