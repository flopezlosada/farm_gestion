<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Módulo LAR (Laboratorio Agroecológico Rural): tabla `lar_project`.
 *
 * Cada fila es un proyecto que se dinamiza desde La Cerrada (Campus Rural,
 * formación IMIDRA, wwoofing, proyectos nacionales/internacionales…), con su
 * ficha rica (título, tipo, resumen, cuerpo), estado (activo/finalizado) y flag
 * de publicado. Las fotos NO viven aquí: cuelgan de la tabla `image` existente
 * por media polimórfica (object_class = 'larproject'), así que no se crea tabla
 * de galería.
 *
 * Idempotente: CREATE TABLE IF NOT EXISTS.
 *
 * Recordatorio operativo: aplicar también a db_prod_snapshot y a csastaging
 * (staging vía phpMyAdmin al desplegar; sin SSH no hay comando en el hosting).
 */
final class Version20260707120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Módulo LAR: tabla lar_project (proyectos dinamizados desde La Cerrada)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS lar_project (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            type VARCHAR(32) NOT NULL,
            summary VARCHAR(500) NOT NULL,
            content LONGTEXT DEFAULT NULL,
            status VARCHAR(16) DEFAULT \'active\' NOT NULL,
            published TINYINT(1) DEFAULT 0 NOT NULL,
            position INT DEFAULT 0 NOT NULL,
            created DATETIME NOT NULL,
            updated DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_lar_project_slug (slug),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lar_project');
    }
}
