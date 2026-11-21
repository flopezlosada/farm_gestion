-- Un pedido del grupo de consumo puede tener varios productores.
--
-- Crea la tabla pedido-productores, copia a ella el productor que hoy lleva cada pedido
-- en `consumer_group_round.producer_id` y hace esa columna NULLABLE (el código nuevo ya
-- no la mapea: con NOT NULL y sin valor por defecto su INSERT lo rechazaría MySQL).
--
-- ORDEN DE DESPLIEGUE: ANTES del código. La columna `producer_id` NO se borra aquí: eso
-- va DESPUÉS, en `consumer-group-round-drop-producer.sql`.
--
-- El INSERT IGNORE es idempotente y hay que RELANZARLO justo al subir el código: entre
-- este SQL y la subida, el código viejo sigue creando pedidos solo con `producer_id`.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.

CREATE TABLE consumer_group_round_producer (round_id INT NOT NULL, producer_id INT NOT NULL, INDEX IDX_371B97BAA6005CA0 (round_id), INDEX IDX_371B97BA89B658FE (producer_id), PRIMARY KEY(round_id, producer_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE consumer_group_round_producer ADD CONSTRAINT FK_371B97BAA6005CA0 FOREIGN KEY (round_id) REFERENCES consumer_group_round (id) ON DELETE CASCADE;
ALTER TABLE consumer_group_round_producer ADD CONSTRAINT FK_371B97BA89B658FE FOREIGN KEY (producer_id) REFERENCES consumer_group_producer (id) ON DELETE RESTRICT;
INSERT IGNORE INTO consumer_group_round_producer (round_id, producer_id) SELECT id, producer_id FROM consumer_group_round WHERE producer_id IS NOT NULL;
ALTER TABLE consumer_group_round MODIFY producer_id INT DEFAULT NULL;
