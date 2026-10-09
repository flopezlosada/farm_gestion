<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `telegram_link`: quién puede mandar facturas al bot de Telegram. Una fila por
 * persona: primero invitación (código de un uso), luego vínculo (id de Telegram).
 *
 * Los nombres de índices y claves ajenas son los que genera Doctrine, para que el
 * esquema y el mapeo no difieran en nada.
 *
 * Sólo AÑADE una tabla: va ANTES del código al desplegar.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'telegram_link: personas que pueden mandar facturas al bot de Telegram';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS telegram_link (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                telegram_user_id BIGINT DEFAULT NULL,
                telegram_name VARCHAR(150) DEFAULT NULL,
                invitation_code VARCHAR(64) DEFAULT NULL,
                invitation_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                linked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL,
                UNIQUE INDEX UNIQ_4ABFBFE1A76ED395 (user_id),
                UNIQUE INDEX UNIQ_4ABFBFE1FC28B263 (telegram_user_id),
                UNIQUE INDEX UNIQ_4ABFBFE1BA14FCCC (invitation_code),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE telegram_link ADD CONSTRAINT FK_4ABFBFE1A76ED395 FOREIGN KEY (user_id) REFERENCES fos_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE telegram_link');
    }
}
