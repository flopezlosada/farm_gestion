-- Cuándo se avisó a la asociación de que una encuesta está abierta (sale sola
-- al abrirla; el botón «Reintentar el aviso» completa un envío cortado).
--
-- NO ES EL CANDADO ANTIDUPLICADOS: eso lo garantiza `emitted_effect` (un apunte
-- por persona y encuesta, ver SurveyAnnouncer). Esta columna es la memoria
-- VISIBLE: lo que lee el equipo en el listado para saber si el aviso salió.
--
-- SÓLO PARA ENTORNOS QUE YA TIENEN LA TABLA `survey` SIN ESTA COLUMNA: las
-- bases locales (db, db_prod_snapshot, db_test) y cualquier otra montada a mano
-- antes de survey.sql. Donde la tabla se creó con survey.sql la columna ya está,
-- y esto se niega a correr («Duplicate column name»), que es lo que tiene que
-- pasar.
--
-- ORDEN DE DESPLIEGUE: ANTES que el código. La mapea la entidad Survey, así que
-- sin ella cualquier pantalla de encuestas revienta al hidratar.

ALTER TABLE survey
    ADD announced_at DATETIME DEFAULT NULL COMMENT 'Cuándo se avisó a las socias de que está abierta';
