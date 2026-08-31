-- Quién coordina una tarea de voluntariado concreta.
--
-- Apunta a `partner` y no a `fos_user`: quien coordina una tarea suelta puede
-- ser cualquier socix, no hace falta que tenga cuenta. Es distinto de
-- `volunteer_category_coordinator`, que sí cuelga de fos_user porque de aquello
-- se derivan permisos de gestión.
--
-- ON DELETE SET NULL: si se borra la ficha de quien coordinaba, la tarea se
-- queda sin coordinación pero NO se borra. Perder el dato es recuperable;
-- perder la tarea, no.
--
-- ⚠️ ESTE FICHERO SE ESCRIBIÓ TARDE. La columna llevaba aplicada a mano en las
-- bases locales desde el commit que la introdujo, pero sin DDL versionado, así
-- que un despliegue a producción habría subido el código sin ella. Se descubrió
-- el 2026-08-31 comparando un dump de producción contra el esquema que espera
-- `main`. Ver el aviso de [[schema_drift_anotaciones_vs_db]]: el DDL a mano que
-- no se versiona es invisible hasta que rompe.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.

ALTER TABLE volunteer_offer ADD coordinator_id INT DEFAULT NULL;

CREATE INDEX idx_volunteer_offer_coordinator ON volunteer_offer (coordinator_id);

ALTER TABLE volunteer_offer
    ADD CONSTRAINT fk_volunteer_offer_coordinator FOREIGN KEY (coordinator_id) REFERENCES partner (id) ON DELETE SET NULL;
