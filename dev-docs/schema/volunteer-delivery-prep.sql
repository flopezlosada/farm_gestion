-- ============================================================================
-- Montaje del reparto — columna volunteer_category.delivery_prep.
--
-- Marca cuál de las áreas de voluntariado es la de montar las cestas de un
-- punto de recogida. De ella sale el bloque "quién te prepara la cesta" del
-- panel del socix, y el aviso de que no se ha apuntado nadie.
--
-- El código (VolunteerCategory) MAPEA esta columna: hay que añadirla a la BBDD
-- ANTES de desplegar el código, o Doctrine la espera y revienta cualquier
-- pantalla que toque voluntariado.
--
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod.
-- Sin datos personales; idempotencia no nativa: correr una sola vez por entorno.
--
-- Después, EN LA WEB (Voluntariado › Áreas), marcar "Es el montaje del reparto"
-- en el área que corresponda. Sin marcarla, nada cambia: la columna a 0 deja el
-- bloque apagado en todos los puntos, que es el estado de partida correcto.
-- ============================================================================

ALTER TABLE volunteer_category ADD delivery_prep TINYINT(1) DEFAULT 0 NOT NULL;
