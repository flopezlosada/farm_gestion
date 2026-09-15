-- El cierre de apuntes de una ronda del grupo de consumo pasa de fecha+hora a
-- solo día: la comisión nunca decidía una hora concreta, y el selector de hora
-- del formulario no funcionaba (desplegable vacío) sin aportar nada real.
-- MySQL trunca la parte horaria al convertir DATETIME -> DATE.
--
-- La fecha de entrega pasa a nullable: al abrir la ronda no siempre se conoce
-- todavía el día de entrega del productor (se añade después).
--
-- ORDEN DE DESPLIEGUE: estas columnas ANTES que el código. Las mapea la
-- entidad ConsumerGroupRound, así que sin ellas revienta el módulo entero.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.

ALTER TABLE consumer_group_round
    MODIFY orders_close_at DATE NOT NULL COMMENT 'Día de cierre de apuntes (sin hora)',
    MODIFY delivery_date DATE DEFAULT NULL COMMENT 'Día de entrega; puede no conocerse aún al abrir la ronda';
