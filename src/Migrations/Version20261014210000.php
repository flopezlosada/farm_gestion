<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El mandato de domiciliación en la ficha del socix que paga: su referencia (única,
 * el banco no deja repetirla) y la fecha en que lo firmó.
 *
 * Sólo AÑADE columnas que admiten nulo: va ANTES del código al desplegar, y el
 * código viejo no las mapea ni las echa en falta.
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261014210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mandato de domiciliación en la ficha del socix';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE partner ADD mandate_reference VARCHAR(35) DEFAULT NULL, ADD mandate_signed_on DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_partner_mandate_reference ON partner (mandate_reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_partner_mandate_reference ON partner');
        $this->addSql('ALTER TABLE partner DROP mandate_reference, DROP mandate_signed_on');
    }
}
