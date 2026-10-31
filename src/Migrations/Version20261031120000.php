<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Arqueos de caja que llegan por el bot, y el `update_id` de Telegram de cada envío
 * (para que un mensaje repetido no registre dos arqueos).
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine, para que el
 * esquema y el mapeo no difieran en nada.
 *
 * Sólo CREA una tabla y AÑADE una columna que admite NULL: va ANTES del código al
 * desplegar, y el código viejo ni lo ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: arqueos de caja por el bot y update_id de los envíos de Telegram';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE cash_count (id INT AUTO_INCREMENT NOT NULL, account_id INT NOT NULL, counted_by_id INT DEFAULT NULL, count_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', counted NUMERIC(10, 2) NOT NULL, book_balance NUMERIC(10, 2) NOT NULL, difference NUMERIC(10, 2) NOT NULL, received_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', message_text VARCHAR(255) NOT NULL, telegram_update_id BIGINT DEFAULT NULL, UNIQUE INDEX UNIQ_B6E7DF4EADC5A602 (telegram_update_id), INDEX IDX_B6E7DF4E9B6B5FBA (account_id), INDEX IDX_B6E7DF4E7D70F0D2 (counted_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE cash_count ADD CONSTRAINT FK_B6E7DF4E9B6B5FBA FOREIGN KEY (account_id) REFERENCES financial_account (id)');
        $this->addSql('ALTER TABLE cash_count ADD CONSTRAINT FK_B6E7DF4E7D70F0D2 FOREIGN KEY (counted_by_id) REFERENCES fos_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE telegram_submission ADD update_id BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_submission DROP update_id');
        $this->addSql('DROP TABLE cash_count');
    }
}
