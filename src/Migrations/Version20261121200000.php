<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupo de consumo: un pedido puede tener varios productores.
 *
 * Crea la tabla `consumer_group_round_producer` (pedido, productor), copia a ella el
 * productor que hasta ahora llevaba cada pedido en `consumer_group_round.producer_id` y
 * hace esa columna NULLABLE: el código nuevo ya no la mapea, y con NOT NULL y sin valor
 * por defecto su INSERT lo rechazaría MySQL en modo estricto.
 *
 * La columna NO se borra aquí. El DROP va DESPUÉS de desplegar el código
 * (`dev-docs/schema/consumer-group-round-drop-producer.sql`): hasta entonces el código
 * viejo sigue escribiéndola y se puede volver atrás sin perder el productor.
 *
 * Va ANTES del código. El backfill es idempotente (INSERT IGNORE): hay que relanzarlo
 * justo al desplegar, porque entre este SQL y la subida del código el código viejo sigue
 * creando pedidos solo con `producer_id`.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261121200000 extends AbstractMigration
{
    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Grupo de consumo: tabla pedido-productores y producer_id nullable';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE consumer_group_round_producer (round_id INT NOT NULL, producer_id INT NOT NULL, INDEX IDX_371B97BAA6005CA0 (round_id), INDEX IDX_371B97BA89B658FE (producer_id), PRIMARY KEY(round_id, producer_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE consumer_group_round_producer ADD CONSTRAINT FK_371B97BAA6005CA0 FOREIGN KEY (round_id) REFERENCES consumer_group_round (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE consumer_group_round_producer ADD CONSTRAINT FK_371B97BA89B658FE FOREIGN KEY (producer_id) REFERENCES consumer_group_producer (id) ON DELETE RESTRICT');
        $this->addSql('INSERT IGNORE INTO consumer_group_round_producer (round_id, producer_id) SELECT id, producer_id FROM consumer_group_round WHERE producer_id IS NOT NULL');
        $this->addSql('ALTER TABLE consumer_group_round MODIFY producer_id INT DEFAULT NULL');
    }

    /**
     * {@inheritdoc}
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE consumer_group_round r SET r.producer_id = (SELECT MIN(rp.producer_id) FROM consumer_group_round_producer rp WHERE rp.round_id = r.id) WHERE r.producer_id IS NULL');
        $this->addSql('ALTER TABLE consumer_group_round MODIFY producer_id INT NOT NULL');
        $this->addSql('DROP TABLE consumer_group_round_producer');
    }
}
