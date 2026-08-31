-- Quién coordina UNA tarea, congelado en ella.
--
-- No se deriva del área aunque el área ya tenga coordinadores
-- (`volunteer_category_coordinator`), y ésa es la razón de que exista la
-- columna: la coordinación de un área CAMBIA. Si la tarea leyera quién coordina
-- Reparto hoy, el día que esa persona lo deje todas las tareas del año pasado
-- dirían que las coordinó quien acaba de entrar. Es el mismo motivo por el que
-- `volunteer_signup.credited_minutes` congela las horas en vez de leerlas de la
-- oferta.
--
-- Se rellena SOLO al crear la tarea cuando el área tiene un único coordinador,
-- que es el caso normal; con varios se elige. Así no hay nada que rellenar
-- mientras no haya duda de quién es.
--
-- Va contra `partner` y no contra `fos_user` —al revés que los coordinadores de
-- área— porque aquí lo que importa es a quién se le atribuye el trabajo, y eso
-- es un socix con ficha.
--
-- ON DELETE SET NULL: si un socix se borra, la tarea sobrevive sin coordinador.
-- Perder la referencia es aceptable; perder la tarea y con ella las horas de
-- todo el mundo, no.
--
-- Aplicar en las tres bases de trabajo: db, db_prod_snapshot y db_test.

ALTER TABLE volunteer_offer
    ADD coordinator_id INT DEFAULT NULL;

ALTER TABLE volunteer_offer
    ADD CONSTRAINT fk_volunteer_offer_coordinator
    FOREIGN KEY (coordinator_id) REFERENCES partner (id) ON DELETE SET NULL;

CREATE INDEX idx_volunteer_offer_coordinator ON volunteer_offer (coordinator_id);
