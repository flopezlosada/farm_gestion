<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recibos devueltos: de quién es cada devolución del banco y cómo se cerró
 * (volver a cobrar, pagado aparte, no se debía, perdido).
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
final class Version20261026180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contabilidad: recibos devueltos de las remesas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE remittance_return (id INT AUTO_INCREMENT NOT NULL, line_id INT NOT NULL, entry_id INT DEFAULT NULL, paid_with_id INT DEFAULT NULL, recharged_in_id INT DEFAULT NULL, resolved_by_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, outcome VARCHAR(20) DEFAULT NULL, resolved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_9B957DF04D7B7542 (line_id), UNIQUE INDEX UNIQ_9B957DF0BA364942 (entry_id), INDEX IDX_9B957DF034A25ED (paid_with_id), UNIQUE INDEX UNIQ_9B957DF07B0520ED (recharged_in_id), INDEX IDX_9B957DF06713A32B (resolved_by_id), INDEX IDX_9B957DF0B03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("ALTER TABLE remittance_return ADD CONSTRAINT FK_9B957DF04D7B7542 FOREIGN KEY (line_id) REFERENCES remittance_line (id) ON DELETE CASCADE");
        $this->addSql("ALTER TABLE remittance_return ADD CONSTRAINT FK_9B957DF0BA364942 FOREIGN KEY (entry_id) REFERENCES account_entry (id) ON DELETE SET NULL");
        $this->addSql("ALTER TABLE remittance_return ADD CONSTRAINT FK_9B957DF034A25ED FOREIGN KEY (paid_with_id) REFERENCES account_entry (id) ON DELETE SET NULL");
        $this->addSql("ALTER TABLE remittance_return ADD CONSTRAINT FK_9B957DF07B0520ED FOREIGN KEY (recharged_in_id) REFERENCES remittance_line (id) ON DELETE SET NULL");
        $this->addSql("ALTER TABLE remittance_return ADD CONSTRAINT FK_9B957DF06713A32B FOREIGN KEY (resolved_by_id) REFERENCES fos_user (id) ON DELETE SET NULL");
        $this->addSql("ALTER TABLE remittance_return ADD CONSTRAINT FK_9B957DF0B03A8386 FOREIGN KEY (created_by_id) REFERENCES fos_user (id) ON DELETE SET NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE remittance_return');
    }
}
