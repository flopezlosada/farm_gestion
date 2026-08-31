<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupo de consumo: "confirmado" pasa a ser un FLAG independiente del estado del
 * plazo (un pedido puede estar confirmado y aún abierto). Añade
 * `consumer_group_round.confirmed` y hace backfill de los antiguos status=2
 * (CONFIRMED) → confirmed=1 + status=1 (cerrado).
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin.
 */
final class Version20260702130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grupo de consumo: consumer_group_round.confirmed (flag) + backfill status=2';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_round ADD confirmed TINYINT(1) NOT NULL DEFAULT 0');
        // Los antiguos "confirmados" (status=2) pasan a confirmed=1 y estado cerrado (1).
        $this->addSql('UPDATE consumer_group_round SET confirmed = 1, status = 1 WHERE status = 2');
    }

    public function down(Schema $schema): void
    {
        // Revertir: los confirmados vuelven a status=2 (aprox.) y se quita la columna.
        $this->addSql('UPDATE consumer_group_round SET status = 2 WHERE confirmed = 1 AND status = 1');
        $this->addSql('ALTER TABLE consumer_group_round DROP confirmed');
    }
}
