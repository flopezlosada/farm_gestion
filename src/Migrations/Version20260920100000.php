<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `consumer_group_producer.minimum_note` era una nota de mínimo por defecto que
 * nunca se leía en ningún sitio (ni precargaba la ronda ni nada): puro campo
 * muerto, duplicado del mínimo real que vive por RONDA
 * ({@see \App\Entity\ConsumerGroupRound::$minimumCondition}). Se retira.
 *
 * `activity_description`: a qué se dedica el productor (texto libre, se enseña
 * en su ficha). El nombre identifica la actividad/empresa, no lo describe.
 */
final class Version20260920100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'consumer_group_producer: quita minimum_note (muerto) y añade activity_description';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_producer DROP minimum_note');
        $this->addSql("ALTER TABLE consumer_group_producer ADD activity_description LONGTEXT DEFAULT NULL COMMENT 'A qué se dedica el productor'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_producer DROP activity_description');
        $this->addSql("ALTER TABLE consumer_group_producer ADD minimum_note VARCHAR(255) DEFAULT NULL");
    }
}
