<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `consumer_group_product.reference_price` se retira: nadie lo rellenaba desde
 * ningún formulario (se quitó del alta/edición de producto) y el precio de
 * arranque de un producto en una ronda nueva ahora sale del histórico de la
 * ÚLTIMA ronda que lo usó ({@see \App\Repository\ConsumerGroupRoundItemRepository::findLastPriceForProduct()}),
 * no de un valor fijo en el catálogo.
 */
final class Version20260920120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'consumer_group_product: quita reference_price (muerto, sin formulario que lo rellene)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_product DROP reference_price');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_product ADD reference_price NUMERIC(8, 2) DEFAULT NULL');
    }
}
