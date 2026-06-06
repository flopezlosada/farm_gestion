<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sub-fase 8.8d (2026-05-26): añade `partner_basket_share.payer_partner_id`
 * para modelar "pagar la cesta de otra persona".
 *
 * Caso de uso confirmado por Ana Villa: Pablo Angulo paga la cesta de
 * Nuria del Río; María Puebla paga la cesta de Nayua (donación);
 * Daniela tiene donante por confirmar. Antes había más casos.
 *
 * Modelo: PBS.partner_id sigue siendo el receptor. payer_partner_id
 * nullable apunta al pagador externo si es distinto. NULL = paga el
 * propio receptor (caso normal).
 *
 * Populate inicial: PBS 229 (NURIA DEL RÍO id 141) → payer_partner_id =
 * 17 (PABLO ANGULO ARDOY). Confirmado en sesión 2026-05-26 con Inés y
 * Mónica.
 *
 * No se popula NAYUA (PBS 232) ni DANIELA (PBS 231) hasta que Ana Villa
 * confirme los donantes: María Puebla para Nayua (id pendiente) y por
 * confirmar para Daniela (¿Manolo/MariCarmen?).
 */
final class Version20260526210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sub-fase 8.8d: partner_basket_share.payer_partner_id + populate Pablo→Nuria';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner_basket_share
            ADD COLUMN payer_partner_id INT DEFAULT NULL,
            ADD CONSTRAINT FK_pbs_payer_partner FOREIGN KEY (payer_partner_id) REFERENCES partner (id),
            ADD INDEX IDX_pbs_payer_partner (payer_partner_id)');

        // Pablo Angulo (id 17) paga la cesta quincenal La Cabrera de Nuria del Río (id 141, PBS 229).
        $this->addSql('UPDATE partner_basket_share SET payer_partner_id = 17 WHERE id = 229');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner_basket_share DROP FOREIGN KEY FK_pbs_payer_partner');
        $this->addSql('ALTER TABLE partner_basket_share DROP INDEX IDX_pbs_payer_partner');
        $this->addSql('ALTER TABLE partner_basket_share DROP COLUMN payer_partner_id');
    }
}
