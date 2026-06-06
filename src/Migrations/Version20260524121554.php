<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Añade los campos necesarios para la importación de socios desde el CSV
 * limpio (cruce COBROS + LISTADO + PDFs de reparto):
 *
 *   partner.iban                       → remesa SEPA del titular legal
 *   partner.notes                      → observaciones de COBROS
 *   partner_basket_share.transport_price → aporte de transporte mensual
 *
 * Todos nullable: la importación inicial cargará lo que tenga y se
 * completa después conforme se vayan obteniendo datos de los 8 grupos
 * sin PDF (Cascorro, Legazpi, MIDORI, Chamberí, Talamanca, Manzanares El
 * Real, Madrid, Navas de Buitrago).
 */
final class Version20260524121554 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'partner.iban + partner.notes + partner_basket_share.transport_price';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner ADD iban VARCHAR(34) DEFAULT NULL, ADD notes LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE partner_basket_share ADD transport_price NUMERIC(8, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner DROP iban, DROP notes');
        $this->addSql('ALTER TABLE partner_basket_share DROP transport_price');
    }
}
