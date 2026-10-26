<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Traspasos por confirmar: los que alguien cuenta por el bot y esperan a que se
 * revisen en la web antes de entrar en el libro.
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
final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: traspasos por confirmar que llegan por el bot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE pending_transfer (id INT AUTO_INCREMENT NOT NULL, from_account_id INT DEFAULT NULL, to_account_id INT DEFAULT NULL, sent_by_id INT NOT NULL, entry_id INT DEFAULT NULL, amount NUMERIC(10, 2) DEFAULT NULL, transfer_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', concept VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_C7F76FE5B0CF99BD (from_account_id), INDEX IDX_C7F76FE5BC58BDC7 (to_account_id), INDEX IDX_C7F76FE5A45BB98C (sent_by_id), UNIQUE INDEX UNIQ_C7F76FE5BA364942 (entry_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE pending_transfer ADD CONSTRAINT FK_C7F76FE5B0CF99BD FOREIGN KEY (from_account_id) REFERENCES financial_account (id)');
        $this->addSql('ALTER TABLE pending_transfer ADD CONSTRAINT FK_C7F76FE5BC58BDC7 FOREIGN KEY (to_account_id) REFERENCES financial_account (id)');
        $this->addSql('ALTER TABLE pending_transfer ADD CONSTRAINT FK_C7F76FE5A45BB98C FOREIGN KEY (sent_by_id) REFERENCES fos_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pending_transfer ADD CONSTRAINT FK_C7F76FE5BA364942 FOREIGN KEY (entry_id) REFERENCES account_entry (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pending_transfer');
    }
}
