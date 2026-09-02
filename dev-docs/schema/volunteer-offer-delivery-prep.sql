-- ============================================================================
-- El montaje de las cestas, en la convocatoria: dos columnas en
-- `volunteer_offer`.
--
-- `delivery_prep`      esta convocatoria es el montaje de las cestas de su
--                      punto de recogida. Sustituye a la casilla del tipo de
--                      trabajo (`volunteer_category.delivery_prep`), que
--                      señalaba una sola cosa en toda la asociación y permitía
--                      marcarla cero veces o dos. NADIE la marca a mano: la
--                      pone quien crea la convocatoria, que es el sistema.
--
-- `repeat_offset_days` días entre el trabajo y la fecha que dicta el calendario
--                      de reparto: 0 el mismo día, -1 la víspera. Existe porque
--                      las cestas se montan antes de repartirlas, a veces la
--                      tarde anterior, y la cadencia "los días que haya reparto"
--                      sólo sabía convocar el día físico de la entrega.
--
-- El índice acompaña a la consulta que busca la convocatoria de montaje de un
-- punto, que se hace en cada guardado del punto y en cada pasada del cron.
--
-- El código (App\Entity\VolunteerOffer) MAPEA estas columnas: hay que añadirlas
-- ANTES de desplegar el código, o Doctrine las espera y revienta todo
-- voluntariado.
--
-- Va DESPUÉS de `dev-docs/schema/volunteer-shift.sql` (la tabla ya tiene que
-- llevar sus columnas de receta) y ANTES de
-- `dev-docs/schema/volunteer-category-delivery-prep-drop.sql`.
--
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod.
-- Sin datos personales; idempotencia no nativa: correr una sola vez por entorno.
-- ============================================================================

ALTER TABLE volunteer_offer
    ADD delivery_prep TINYINT(1) DEFAULT 0 NOT NULL,
    ADD repeat_offset_days SMALLINT DEFAULT 0 NOT NULL;

CREATE INDEX idx_volunteer_offer_delivery_prep ON volunteer_offer (node_id, delivery_prep);
