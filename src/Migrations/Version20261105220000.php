<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Datos personales del trabajador: DNI/NIE, teléfono, correo y dirección. Todos
 * admiten null: las fichas que ya existen no los tienen.
 *
 * Sólo añade columnas: va ANTES del código.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261105220000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Trabajador: DNI/NIE, teléfono, correo y dirección';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker ADD nif VARCHAR(9) DEFAULT NULL, ADD phone VARCHAR(20) DEFAULT NULL, ADD email VARCHAR(255) DEFAULT NULL, ADD address VARCHAR(255) DEFAULT NULL');
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker DROP nif, DROP phone, DROP email, DROP address');
    }
}
