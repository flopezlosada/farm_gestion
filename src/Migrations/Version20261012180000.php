<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El cobro de una factura emitida: el apunte del banco en que entró el dinero.
 * Único (un apunte cobra una sola factura) y SET NULL (si se borra el apunte, la
 * factura vuelve a estar sin cobrar).
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine, para que el
 * esquema y el mapeo no difieran en nada.
 *
 * Sólo AÑADE una columna nullable: va ANTES del código al desplegar, y el código
 * viejo sigue funcionando con ella.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261012180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Facturas emitidas: el apunte en que se cobraron';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issued_invoice ADD account_entry_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9FE1829DC2F8ABF4 ON issued_invoice (account_entry_id)');
        $this->addSql('ALTER TABLE issued_invoice ADD CONSTRAINT FK_9FE1829DC2F8ABF4 FOREIGN KEY (account_entry_id) REFERENCES account_entry (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issued_invoice DROP FOREIGN KEY FK_9FE1829DC2F8ABF4');
        $this->addSql('DROP INDEX UNIQ_9FE1829DC2F8ABF4 ON issued_invoice');
        $this->addSql('ALTER TABLE issued_invoice DROP account_entry_id');
    }
}
