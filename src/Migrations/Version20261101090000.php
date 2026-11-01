<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lo entendido de una nota de voz que el bot enseña para confirmar (un arqueo de caja
 * dicho): se guarda para que al confirmar se registre eso y no otra lectura del audio.
 *
 * Sólo AÑADE una columna que admite NULL: va ANTES del código al desplegar, y el código
 * viejo ni la ve.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261101090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Telegram: lo entendido de una nota de voz, para confirmarlo antes de guardarlo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_submission ADD transcript LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_submission DROP transcript');
    }
}
