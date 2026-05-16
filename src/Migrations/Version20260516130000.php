<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aplica el vínculo User-Partner (fos_user.partner_id) que el modelo PHP
 * declara desde el commit 68dab3a "feat(rbac): nuevo sistema de roles +
 * vínculo User-Partner" pero que nunca llegó al esquema. Sin esta columna
 * la query de autenticación falla con "Unknown column 't0.partner_id'".
 */
final class Version20260516130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fos_user.partner_id: vínculo opcional User-Partner';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on \'mysql\'.'
        );

        $this->addSql('ALTER TABLE fos_user ADD partner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fos_user '
            . 'ADD CONSTRAINT FK_957A64799393F8FE '
            . 'FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_957A64799393F8FE ON fos_user (partner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on \'mysql\'.'
        );

        $this->addSql('ALTER TABLE fos_user DROP FOREIGN KEY FK_957A64799393F8FE');
        $this->addSql('DROP INDEX UNIQ_957A64799393F8FE ON fos_user');
        $this->addSql('ALTER TABLE fos_user DROP partner_id');
    }
}
