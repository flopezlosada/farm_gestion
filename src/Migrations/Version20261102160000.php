<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El apunte del que sale cada parte al partir uno: las dos fichas se enlazan y la
 * próxima vez que llega el mismo cargo se puede partir igual.
 *
 * Sólo AÑADE una columna que admite NULL, su índice y su clave ajena: va ANTES del
 * código al desplegar. Los ya partidos se enlazan a mano (en local, 562 → 543; prod
 * aún no tiene contabilidad).
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261102160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Apunte del que sale cada parte de un apunte partido';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_entry ADD split_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE account_entry ADD CONSTRAINT FK_1DAE44019FD789BF FOREIGN KEY (split_from_id) REFERENCES account_entry (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1DAE44019FD789BF ON account_entry (split_from_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_entry DROP FOREIGN KEY FK_1DAE44019FD789BF');
        $this->addSql('DROP INDEX IDX_1DAE44019FD789BF ON account_entry');
        $this->addSql('ALTER TABLE account_entry DROP split_from_id');
    }
}
