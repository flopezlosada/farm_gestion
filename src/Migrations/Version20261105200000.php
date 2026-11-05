<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marca en la ficha si es socia (`partner.is_member`). Todas las fichas que hay lo
 * son, de ahí el valor por defecto 1: nada cambia hasta que alguien desmarque la
 * ficha de cesta de una trabajadora que no es socia.
 *
 * Es un ADD con valor por defecto: va ANTES del código, y el código viejo lo ignora.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261105200000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Socixs: la ficha dice si es socia (is_member)';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner ADD is_member TINYINT(1) DEFAULT 1 NOT NULL');
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner DROP is_member');
    }
}
