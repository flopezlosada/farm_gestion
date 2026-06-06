<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `partner.email` pasa a nullable. En la realidad un 42% de los socixs
 * activos del CSV de importación no tiene email registrado (ver
 * listado_final.csv). Forzar NOT NULL nos obligaría a placeholders
 * artificiales que luego habría que limpiar.
 */
final class Version20260524131500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'partner.email pasa a nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner MODIFY email VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE partner MODIFY email VARCHAR(255) NOT NULL DEFAULT ''");
    }
}
