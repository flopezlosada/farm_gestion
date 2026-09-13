-- ¿Este producto del grupo de consumo se puede pedir en MEDIAS unidades?
--
-- Lo decide cada producto y no el formulario. Con un paso decimal para todos,
-- las flechas del campo llevaban a pedir «0,03 garrafas de 5 L»; con un paso
-- entero para todos, no había forma de pedir medio kilo de queso. Medias y no
-- decimales libres: lo que se vende por peso en un grupo de consumo se pide de
-- medio en medio, y con decimales libres vuelve a colarse el 0,03.
--
-- Por defecto 0 (unidades enteras), que es lo que hay hoy en el catálogo: todo
-- son formatos cerrados —garrafa de 5 L, saco de 5 kg, caja de 10 kg—. La
-- comisión marca la casilla en lo que se venda al peso.
--
-- ORDEN DE DESPLIEGUE: esta columna ANTES que el código. La mapea la entidad
-- ConsumerGroupProduct, así que sin ella revientan el catálogo, el formulario de
-- apuntarse y la hoja de reparto.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.

ALTER TABLE consumer_group_product
    ADD half_units TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se puede pedir en medias unidades (medio kilo)';
