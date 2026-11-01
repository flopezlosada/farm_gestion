<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trabajadores: fecha de baja (sustituye a `active`), su ficha de socix y qué pasa con
 * su cesta tras la baja.
 *
 * `active` deja de leerse pero NO se borra aquí: va ANTES del código al desplegar y el
 * código viejo sigue usándola. Se borra en una migración aparte, cuando este código ya
 * esté en producción. A quien ya estaba inactivo se le pone como último día el anterior
 * a la última vez que se tocó su ficha (inactivo = ya no fichaba): no hay otra fecha
 * guardada, y conviene revisarla. El UPDATE de la baja se repite justo DESPUÉS de
 * desplegar el código: quien se desactive entre la SQL y el código sólo tendrá `active`.
 *
 * La ficha de socix se trae de la cuenta de quien ya tiene las dos facetas en el mismo
 * usuario (`fos_user.partner_id` + `fos_user.worker_id`).
 *
 * Recordatorio operativo: aplicar también a golden/staging/prod vía phpMyAdmin
 * al desplegar (NUNCA schema:update --force, por el drift de índices).
 */
final class Version20261101220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trabajadores: fecha de baja, ficha de socix y cesta tras la baja';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE worker ADD leave_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', ADD partner_id INT DEFAULT NULL, ADD basket_after_leave VARCHAR(10) DEFAULT NULL, ADD basket_gift_until DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'");
        $this->addSql('ALTER TABLE worker ADD CONSTRAINT FK_9FB2BF629393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9FB2BF629393F8FE ON worker (partner_id)');
        $this->addSql('UPDATE worker SET leave_date = DATE(updated) - INTERVAL 1 DAY WHERE active = 0 AND leave_date IS NULL');
        $this->addSql('UPDATE worker w JOIN fos_user u ON u.worker_id = w.id SET w.partner_id = u.partner_id WHERE u.partner_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker DROP FOREIGN KEY FK_9FB2BF629393F8FE');
        $this->addSql('DROP INDEX UNIQ_9FB2BF629393F8FE ON worker');
        $this->addSql('ALTER TABLE worker DROP leave_date, DROP partner_id, DROP basket_after_leave, DROP basket_gift_until');
    }
}
