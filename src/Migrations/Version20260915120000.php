<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `consumer_group_round.orders_close_at` pasa de DATETIME a DATE: la comisión
 * sólo decide un día de cierre, nunca una hora concreta, y el selector de hora
 * no aportaba nada salvo confusión. MySQL trunca la parte horaria al convertir.
 *
 * `consumer_group_round.delivery_date` pasa a nullable: el día de entrega del
 * productor no siempre se conoce al abrir la ronda; se añade después.
 */
final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'consumer_group_round: orders_close_at a DATE (sin hora) y delivery_date nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_round MODIFY orders_close_at DATE NOT NULL');
        $this->addSql('ALTER TABLE consumer_group_round MODIFY delivery_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_round MODIFY orders_close_at DATETIME NOT NULL');
        $this->addSql("ALTER TABLE consumer_group_round MODIFY delivery_date DATE NOT NULL DEFAULT '2026-01-01'");
    }
}
