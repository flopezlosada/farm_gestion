-- Mínimo estructurado de la ronda del grupo de consumo: además del texto libre
-- (minimum_condition), un tipo + valor numérico que la app puede comparar contra
-- el agregado de la ronda para confirmar sola cuando se alcanza.
--
-- minimum_type: 1 = importe total (€), 2 = nº de socias con pedido. NULL = sin
-- mínimo automatizable (se sigue confirmando a mano, como hasta ahora).
--
-- ORDEN DE DESPLIEGUE: estas columnas ANTES que el código.
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.

ALTER TABLE consumer_group_round
    ADD minimum_type SMALLINT DEFAULT NULL COMMENT 'Tipo de mínimo: 1=importe €, 2=nº socias con pedido',
    ADD minimum_value NUMERIC(8, 2) DEFAULT NULL COMMENT 'Umbral del mínimo, en la unidad de minimum_type';
