-- Lo que la asociación pide para el local, además de lo que piden las socias.
--
-- Hasta ahora el pedido que se le pasa al productor salía ENTERO de sumar los
-- apuntes de las socias, así que lo que la comisión encarga para el local —una
-- caja de fruta para la despensa, una garrafa de aceite de reserva— no cabía en
-- ningún sitio y se seguía llevando aparte, a mano, fuera de la web.
--
-- Va en la línea de producto del pedido y no en un pedido a nombre de «la
-- asociación»: un pedido falso se colaría en el recuento de socias, en los
-- avisos, en el control de pagos, en las estadísticas y en la hoja de reparto,
-- que habría que enseñar a ignorarlo en cada sitio.
--
-- Por defecto 0: los pedidos que ya existen no piden nada para el local.
--
-- ORDEN DE DESPLIEGUE: esta columna ANTES que el código. La mapea la entidad
-- ConsumerGroupRoundItem, así que sin ella revienta el módulo entero.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.

ALTER TABLE consumer_group_round_item
    ADD association_quantity NUMERIC(8, 2) DEFAULT '0' NOT NULL COMMENT 'Cantidad que pide la asociación para el local, sin socia detrás';
