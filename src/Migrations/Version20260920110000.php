<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `consumer_group_producer.notes` (notas internas de la comisión) se retira:
 * en la práctica duplicaba a `activity_description` ("a qué se dedica") y
 * generaba confusión sobre qué campo rellenar. Un solo campo de texto libre.
 */
final class Version20260920110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'consumer_group_producer: quita notes (duplicaba activity_description)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_producer DROP notes');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumer_group_producer ADD notes LONGTEXT DEFAULT NULL');
    }
}
