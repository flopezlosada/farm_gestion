-- Bitácora de actividad del grupo de consumo (ConsumerGroupEventLog): quién y
-- cuándo abrió/cerró/confirmó/canceló/entregó un pedido, editó sus productos y
-- precios, apuntó/cambió/vació un pedido de socia, o dio de alta/editó/borró un
-- producto del catálogo de un productor.
--
-- NO es un candado antiduplicados ni una tabla de avisos entregados (esas ya
-- existen: emitted_effect y notification_log). Esta es la bitácora legible de
-- "qué ha pasado", pensada para enseñarse en la ficha del pedido.
--
-- ORDEN DE DESPLIEGUE: esta tabla ANTES que el código. La mapea la entidad
-- ConsumerGroupEventLog, así que sin ella cualquier pantalla que la consulte
-- revienta al hidratar.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.
-- En producción, cuando se encienda el módulo (hoy el feature-flag está apagado).

CREATE TABLE consumer_group_event_log (
    id INT AUTO_INCREMENT NOT NULL,
    round_id INT DEFAULT NULL,
    actor_user_id INT DEFAULT NULL,
    kind VARCHAR(60) NOT NULL,
    actor_label VARCHAR(180) NOT NULL,
    summary VARCHAR(255) NOT NULL,
    occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX IDX_cg_event_round (round_id, occurred_at),
    INDEX IDX_cg_event_occurred (occurred_at),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE consumer_group_event_log
    ADD CONSTRAINT FK_cg_event_round FOREIGN KEY (round_id) REFERENCES consumer_group_round (id) ON DELETE SET NULL,
    ADD CONSTRAINT FK_cg_event_actor FOREIGN KEY (actor_user_id) REFERENCES fos_user (id) ON DELETE SET NULL;
