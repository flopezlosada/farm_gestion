-- Retira `consumer_group_round.producer_id`: los productores de un pedido viven ahora en
-- `consumer_group_round_producer` (ver consumer-group-round-multi-producer.sql).
--
-- ORDEN DE DESPLIEGUE: al revés que una columna nueva. El DROP va DESPUÉS de que el
-- código que ya no la mapea esté arriba; con la columna fuera y el código viejo
-- todavía sirviéndose, Doctrine pediría una columna que no existe y el módulo entero
-- daría 500. Y no hay vuelta atrás una vez que se crean pedidos con el código nuevo:
-- esos pedidos solo tienen la tabla nueva.
--
-- Comprobar antes que ningún pedido se queda sin productor en la tabla nueva:
--   SELECT r.id FROM consumer_group_round r
--   LEFT JOIN consumer_group_round_producer rp ON rp.round_id = r.id
--   WHERE rp.round_id IS NULL;
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.

ALTER TABLE consumer_group_round DROP FOREIGN KEY FK_17D0A12F89B658FE;
DROP INDEX IDX_17D0A12F89B658FE ON consumer_group_round;
ALTER TABLE consumer_group_round DROP producer_id;
