<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migra los users legacy `ROLE_COOP` a `ROLE_GESTION_GRANJA`. ROLE_COOP
 * es el rol histórico de FOSUser para trabajadorxs de la cooperativa; en
 * el rediseño de roles ese grupo es ROLE_GESTION_GRANJA.
 *
 * En el snapshot de prod hay 5 users afectados (loreto, paco, sara,
 * monica, luis). El WHERE es estricto sobre el serialize literal con
 * un único elemento ROLE_COOP para no tocar combinaciones inesperadas.
 *
 * El roles del User está serializado con PHP serialize; el número antes
 * de las comillas (s:9 / s:19) es la longitud del string y tiene que
 * coincidir o unserialize falla. ROLE_GESTION_GRANJA = 19 chars.
 *
 * Idempotente: si los users ya están migrados, el UPDATE afecta 0 filas.
 */
final class Version20260524181323 extends AbstractMigration
{
    private const COOP_SERIALIZED   = 'a:1:{i:0;s:9:"ROLE_COOP";}';
    private const GRANJA_SERIALIZED = 'a:1:{i:0;s:19:"ROLE_GESTION_GRANJA";}';

    public function getDescription(): string
    {
        return 'Migra users ROLE_COOP a ROLE_GESTION_GRANJA';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE fos_user SET roles = :new WHERE roles = :old',
            ['old' => self::COOP_SERIALIZED, 'new' => self::GRANJA_SERIALIZED],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE fos_user SET roles = :old WHERE roles = :new',
            ['old' => self::COOP_SERIALIZED, 'new' => self::GRANJA_SERIALIZED],
        );
    }
}
