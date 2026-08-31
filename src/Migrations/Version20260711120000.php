<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Módulo LAR: portada editable. Tablas `lar_page` (singleton) y `lar_offer`.
 *
 * `lar_page` (una sola fila) guarda los bloques de texto de la portada: la
 * introducción (HTML), los datos de contacto de la coordinación. Las tarjetas de
 * oferta formativa NO son campos fijos: viven en `lar_offer` (una fila por
 * tarjeta, con FK a la portada y orden), para poder tener un número variable.
 *
 * No se inserta contenido aquí: los valores de fábrica viven en
 * {@see \App\Entity\LarPage} y se persisten la primera vez que la coordinación
 * guarda desde el panel; hasta entonces la web los sirve desde la entidad.
 *
 * Idempotente: CREATE TABLE IF NOT EXISTS.
 *
 * Recordatorio operativo: aplicar también a db_prod_snapshot y a csastaging
 * (staging vía phpMyAdmin al desplegar; sin SSH no hay comando en el hosting).
 */
final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Módulo LAR: tablas lar_page y lar_offer (portada editable con oferta variable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS lar_page (
            id INT AUTO_INCREMENT NOT NULL,
            intro LONGTEXT DEFAULT NULL,
            coord_name VARCHAR(150) DEFAULT NULL,
            coord_phone VARCHAR(50) DEFAULT NULL,
            coord_email VARCHAR(180) DEFAULT NULL,
            updated DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS lar_offer (
            id INT AUTO_INCREMENT NOT NULL,
            page_id INT NOT NULL,
            title VARCHAR(150) DEFAULT NULL,
            hours VARCHAR(150) DEFAULT NULL,
            body LONGTEXT DEFAULT NULL,
            position INT DEFAULT 0 NOT NULL,
            INDEX IDX_lar_offer_page (page_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_lar_offer_page FOREIGN KEY (page_id) REFERENCES lar_page (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lar_offer');
        $this->addSql('DROP TABLE lar_page');
    }
}
