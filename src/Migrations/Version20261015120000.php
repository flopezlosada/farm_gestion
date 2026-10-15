<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las pausas de lxs socixs con fechas: desde qué día y hasta cuál.
 *
 * Hasta ahora la pausa era sólo el estado de la ficha, y el reparto no lo miraba:
 * quien estaba pausadx seguía recibiendo cesta. Desde aquí el periodo es lo que
 * consultan el reparto y las cuotas.
 *
 * Se siembra con lo que ya hay, para no perder ni las pausas en curso ni su
 * histórico:
 *  - cada evento PAUSE es una pausa, que acaba con el siguiente evento de ciclo de
 *    vida del socix (reanudación, baja o vuelta). Las que se abrieron y cerraron el
 *    mismo día no dejaron a nadie sin cesta y no se siembran. Una sin evento que la
 *    cierre sólo se siembra abierta si la ficha sigue en pausa: si no, nunca se
 *    cerró con un evento y abrirla ahora pausaría a alguien activx;
 *  - quien está en pausa sin ningún evento PAUSE (fichas importadas así) recibe una
 *    pausa abierta desde la última vez que se tocó su ficha.
 *
 * Los nombres de índice y clave ajena son los que genera Doctrine.
 *
 * Sólo CREA una tabla: va ANTES del código al desplegar. Aplicar también a
 * golden/staging/prod vía phpMyAdmin (NUNCA schema:update --force).
 */
final class Version20261015120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pausas de socixs con fechas, sembradas desde los eventos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE partner_pause (id INT AUTO_INCREMENT NOT NULL, partner_id INT NOT NULL, starts_on DATE NOT NULL COMMENT '(DC2Type:date_immutable)', resumes_on DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', actor VARCHAR(80) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_C4A63E889393F8FE (partner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE partner_pause ADD CONSTRAINT FK_C4A63E889393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE');

        $this->addSql("INSERT INTO partner_pause (partner_id, starts_on, resumes_on, actor, created_at)
            SELECT e.partner_id, DATE(e.occurred_at), e.next_on, e.actor, NOW()
              FROM (SELECT partner_id, type, occurred_at, actor,
                           DATE(LEAD(occurred_at) OVER w) AS next_on
                      FROM partner_event
                     WHERE type IN ('PAUSE', 'RESUME', 'LEAVE', 'JOIN')
                    WINDOW w AS (PARTITION BY partner_id ORDER BY occurred_at, id)) e
              JOIN partner p ON p.id = e.partner_id
             WHERE e.type = 'PAUSE'
               AND (e.next_on > DATE(e.occurred_at) OR (e.next_on IS NULL AND p.status = 'PAUSADO'))");

        $this->addSql("INSERT INTO partner_pause (partner_id, starts_on, resumes_on, actor, created_at)
            SELECT p.id, COALESCE(DATE(p.updated), CURDATE()), NULL, 'cli', NOW()
              FROM partner p
             WHERE p.status = 'PAUSADO'
               AND NOT EXISTS (SELECT 1 FROM partner_pause pp WHERE pp.partner_id = p.id AND pp.resumes_on IS NULL)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE partner_pause');
    }
}
