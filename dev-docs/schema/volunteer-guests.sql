-- Gente de fuera en una tarea de voluntariado.
--
-- Personas que vienen a echar una mano sin ser socixs y sin acompañar a nadie:
-- un grupo de estudiantes, gente de otra asociación, quien pasaba por allí. No
-- caben en `volunteer_signup.companions` porque aquello cuelga SIEMPRE de la
-- inscripción de un socix, y esta gente no tiene de quién colgar.
--
-- Un número y una nota, no filas: no son personas del sistema —no tienen ficha,
-- ni cuenta, ni horas— y darles una les inventaría una identidad que no existe.
-- Lo único que hace falta saber es cuántos brazos son, para que la tarea deje
-- de pedir gente que ya está cubierta, y quiénes eran, para que dentro de tres
-- meses se entienda por qué esa tarea salió adelante con dos socixs.
--
-- NO COMPUTAN HORAS a nadie: las horas son de socixs con ficha.
--
-- Aplicar en las tres bases de trabajo: db, db_prod_snapshot y db_test.

ALTER TABLE volunteer_offer
    ADD guests INT DEFAULT 0 NOT NULL,
    ADD guests_note VARCHAR(160) DEFAULT NULL;
