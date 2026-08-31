-- ============================================================================
-- Tareas destacadas — columna volunteer_offer.featured.
--
-- Sube una tarea a lo alto del panel de cada socix, por delante del orden normal
-- (punto de recogida propio y después fecha). Es el control editorial sobre la
-- portada: hay semanas en las que una cosa importa más que el orden automático.
--
-- DESTACAR NO ES FILTRAR: sin ninguna marcada, la portada sigue enseñando lo de
-- siempre. Por eso arrancar con la columna entera a 0 es el estado correcto y no
-- hay nada que sembrar.
--
-- El código (VolunteerOffer) MAPEA esta columna: hay que añadirla a la BBDD
-- ANTES de desplegar el código, o Doctrine la espera y revienta cualquier
-- pantalla que toque voluntariado.
--
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod.
-- Sin datos personales; idempotencia no nativa: correr una sola vez por entorno.
-- ============================================================================

ALTER TABLE volunteer_offer ADD featured TINYINT(1) DEFAULT 0 NOT NULL;
