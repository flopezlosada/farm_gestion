-- Cuándo se avisó a la asociación de que un pedido del grupo de consumo está
-- abierto (botón «Avisar a las socias» en la ficha del pedido).
--
-- NO ES EL CANDADO ANTIDUPLICADOS, y la distinción importa: que el aviso no
-- salga dos veces lo garantiza `emitted_effect` (un apunte por persona y pedido,
-- ver ConsumerGroupAnnouncer). Esta columna es la memoria VISIBLE: es lo que lee
-- la comisión en la ficha para saber si el pedido ya se ha contado, y lo que
-- hace que el botón pase a «volver a avisar». Sin ella, la única forma de
-- saberlo sería mirar una tabla de registro que esa pantalla no enseña.
--
-- ORDEN DE DESPLIEGUE: esta columna ANTES que el código. La mapea la entidad
-- ConsumerGroupRound, así que sin ella cualquier pantalla del módulo —y el
-- calendario del socix, que consulta sus pedidos— revienta al hidratar.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.
-- En producción, cuando se encienda el módulo (hoy el feature-flag está apagado).

ALTER TABLE consumer_group_round
    ADD announced_at DATETIME DEFAULT NULL COMMENT 'Cuándo se avisó de la apertura a las socias';
