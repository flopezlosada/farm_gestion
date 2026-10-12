<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `telegram_submission`: lo que llega al bot de Telegram, guardado antes de que
 * ningún apartado se lo quede, para poder corregir adónde va (desde Telegram o
 * desde la web) y para dejar pendiente lo que el bot no tiene claro.
 *
 * Los nombres de índices y claves ajenas son los que genera Doctrine, para que el
 * esquema y el mapeo no difieran en nada.
 *
 * Sólo AÑADE una tabla: va ANTES del código al desplegar.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'telegram_submission: envíos al bot, con su fichero y adónde han ido';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS telegram_submission (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                resolved_by_id INT DEFAULT NULL,
                chat_id VARCHAR(32) NOT NULL,
                kind VARCHAR(20) NOT NULL,
                text LONGTEXT DEFAULT NULL,
                file_name VARCHAR(255) DEFAULT NULL,
                original_name VARCHAR(255) DEFAULT NULL,
                mime_type VARCHAR(100) DEFAULT NULL,
                sent_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                status VARCHAR(20) NOT NULL,
                destination VARCHAR(40) DEFAULT NULL,
                target_ref VARCHAR(64) DEFAULT NULL,
                summary VARCHAR(255) DEFAULT NULL,
                bot_message_id INT DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_8915EB89A76ED395 (user_id),
                INDEX IDX_8915EB896713A32B (resolved_by_id),
                INDEX idx_telegram_submission_status (status, created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE telegram_submission ADD CONSTRAINT FK_8915EB89A76ED395 FOREIGN KEY (user_id) REFERENCES fos_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE telegram_submission ADD CONSTRAINT FK_8915EB896713A32B FOREIGN KEY (resolved_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE telegram_submission');
    }
}
