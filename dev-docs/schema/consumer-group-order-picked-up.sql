-- Marca de recogida del pedido del grupo de consumo, igual que la de pago:
-- ni la socia ni la comisión tenían dónde apuntar que el producto ya se
-- había recogido en el nodo.
--
-- Por defecto NOT NULL false: los pedidos que ya existen se consideran no
-- recogidos todavía, que es el estado correcto para cualquier pedido de una
-- ronda que no esté DELIVERED.
--
-- ORDEN DE DESPLIEGUE: estas columnas ANTES que el código. Las mapea la
-- entidad ConsumerGroupOrder, así que sin ellas revienta el módulo entero.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.

ALTER TABLE consumer_group_order
    ADD picked_up TINYINT(1) DEFAULT 0 NOT NULL COMMENT 'Si la socia ha recogido este pedido',
    ADD picked_up_at DATETIME DEFAULT NULL COMMENT 'Cuándo se marcó como recogido';
