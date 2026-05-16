<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Añade fos_user.password_set, el flag que distingue a los Users que ya
 * eligieron su contraseña de los recién creados por la vía de "primer
 * acceso" (magic-link), que arrancan con un password aleatorio
 * placeholder. La primera vez que entran por el link, el panel les
 * fuerza a pasar por /panel/setup antes de seguir.
 *
 * Backfill: todos los Users existentes con password no vacío ya tienen
 * contraseña configurada (la del dump de prod), así que pasan a true.
 * Los nuevos creados via firstAccess() quedarán en false hasta que el
 * socix complete el formulario de setup.
 */
final class Version20260516170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fos_user.password_set: flag de contraseña configurada vs. placeholder';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on \'mysql\'.'
        );

        $this->addSql('ALTER TABLE fos_user ADD password_set TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql("UPDATE fos_user SET password_set = 1 WHERE password IS NOT NULL AND password <> ''");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on \'mysql\'.'
        );

        $this->addSql('ALTER TABLE fos_user DROP password_set');
    }
}
