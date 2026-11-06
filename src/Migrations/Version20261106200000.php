<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Apunte anotado solo al subir un extracto del banco: la app lo crea con la partida
 * de las últimas veces o con la factura que esperaba ese pago, y lo marca para que se
 * vea quién lo hizo. Al corregirlo una persona deja de estarlo.
 *
 * Sólo añade una columna con valor por defecto: va ANTES del código.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261106200000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Apunte: anotado solo desde el extracto del banco';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_entry ADD auto_booked TINYINT(1) DEFAULT 0 NOT NULL');
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_entry DROP auto_booked');
    }
}
