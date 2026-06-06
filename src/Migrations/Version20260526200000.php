<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sub-fase 8.8b (2026-05-26): añade `weekly_basket.delivery_date` para
 * congelar la fecha física de reparto en el momento de generar el
 * WeeklyBasket.
 *
 * Necesario para que el histórico sea inmutable ante cambios futuros de
 * `Node.delivery_weekday` (ej. Madrid pasa de miércoles a martes). Sin
 * esta columna, mirar la fecha de reparto pasada implicaría recalcular
 * con la configuración actual del nodo, lo que reescribiría la historia.
 *
 * Populate inicial: todos los WeeklyBasket actuales son de Torremocha
 * (viernes-ciclo == fecha física), por lo que `delivery_date = basket.date`.
 *
 * Nullable de momento — la NOT NULL constraint entra en una migración
 * posterior cuando esté validado que el generator la rellena en todos
 * los caminos.
 */
final class Version20260526200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sub-fase 8.8b: weekly_basket.delivery_date para congelar día físico de reparto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE weekly_basket ADD COLUMN delivery_date DATE DEFAULT NULL');

        $this->addSql('UPDATE weekly_basket wb
            JOIN basket b ON wb.basket_id = b.id
            SET wb.delivery_date = b.date
            WHERE wb.delivery_date IS NULL');

        $this->addSql('CREATE INDEX IDX_wb_delivery_date ON weekly_basket (delivery_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_wb_delivery_date ON weekly_basket');
        $this->addSql('ALTER TABLE weekly_basket DROP COLUMN delivery_date');
    }
}
