-- ============================================================================
-- MIGRACIÓN DE ESQUEMA A STAGING · 8 de septiembre de 2026
--
-- LO QUE LE FALTA A STAGING, y nada más. No es el fichero del 4 de septiembre
-- repetido: de aquél, staging ya tiene la parte A y casi toda la B —los turnos,
-- el montaje y la rutina—, así que volver a pasarlo entero fallaría en la
-- primera clave ajena duplicada y dejaría el resto sin aplicar.
--
-- CÓMO SE HA SABIDO QUÉ FALTA. Se cargó el dump real de staging
-- (`csastaging.sql`) en una base local y se le preguntó a Doctrine con el código
-- de `main`: `doctrine:schema:update --dump-sql`. Lo que sale, quitando el drift
-- viejo de tipos y de nombres de índice, es exactamente esto.
--
-- ⚠️ LA PARTE 1 VA ANTES DE SUBIR EL CÓDIGO. La entidad mapea la columna nueva;
-- sin ella, cualquier lectura de un `PartnerDeliveryShift` revienta, y eso es
-- TODO el calendario de reparto —gestión y socix— más el generador semanal.
--
-- ⚠️ LA PARTE 3 VA DESPUÉS, y por el motivo contrario: mientras el código viejo
-- siga arriba, esa columna tiene que existir. Una columna de más que el código
-- ya no mapea es inofensiva; una de menos que todavía mapea son 500 en todo el
-- módulo.
--
-- ⚠️ NO ES IDEMPOTENTE. Una vez por entorno.
--
-- ⚠️ En phpMyAdmin, comprobar a mano que la base seleccionada es `csastaging`:
-- `DATABASE()` no es de fiar allí.
-- ============================================================================


-- ============================================================================
-- PARTE 1 · ANTES del código — la columna del traslado sumando
--
-- Viene de la PR #121. El nombre de la clave ajena NO es decorativo: es el que
-- genera Doctrine, y con otro nombre cada `schema:update` futuro propondría
-- recrearla, haciendo crecer el drift.
-- ============================================================================

ALTER TABLE partner_delivery_shift
  ADD accumulated_to_basket_id INT DEFAULT NULL;

ALTER TABLE partner_delivery_shift
  ADD CONSTRAINT FK_B49754EC15F19D1E FOREIGN KEY (accumulated_to_basket_id)
  REFERENCES basket (id) ON DELETE CASCADE;

CREATE INDEX idx_pds_accumulated_to ON partner_delivery_shift (accumulated_to_basket_id);


-- ============================================================================
-- PARTE 2 · Los traslados que ya existían
--
-- Un traslado sumando hecho ANTES de este cambio queda con la columna a NULL,
-- o sea, contado como "no recoge": su cesta seguiría saliendo en la papelera
-- aunque esté colocada en otro día, y quien la "recupere" acaba con una cesta
-- de más en el listado impreso.
--
-- COMPROBADO SOBRE EL DUMP REAL DE STAGING (2026-09-08): hay UNA fila.
--
--   shift 8 · socix 410 · origen 2026-09-11 · destino basket 513 (2026-09-25)
--
-- Se marca a mano y no con el UPDATE con JOIN del fichero del día 4: con una
-- sola fila se ve exactamente qué se escribe.
-- ============================================================================

-- Antes de ejecutar, confirmar que sigue siendo esa fila y sólo esa:
SELECT s.id AS shift_id, s.partner_id, fb.date AS semana_origen,
       e.basket_id AS destino, db.date AS semana_destino, s.created
FROM partner_delivery_shift s
JOIN basket fb ON fb.id = s.from_basket_id
JOIN partner_basket_extra e
       ON e.partner_id = s.partner_id AND e.created = s.created
JOIN basket db ON db.id = e.basket_id
WHERE s.to_basket_id IS NULL
  AND s.component_id IS NULL
  AND s.accumulated_to_basket_id IS NULL
  AND fb.date >= CURDATE()
GROUP BY s.id, s.partner_id, fb.date, e.basket_id, db.date, s.created
ORDER BY fb.date;

UPDATE partner_delivery_shift SET accumulated_to_basket_id = 513 WHERE id = 8;


-- ============================================================================
-- PARTE 3 · DESPUÉS de subir el código
-- ============================================================================

--
-- Retira una columna que el código nuevo ya no usa. Mientras el código viejo
-- siga arriba tiene que existir, así que esto va al final, con la web nueva ya
-- funcionando. Si se te olvida no pasa nada: es una columna de más.
-- ############################################################################
-- ============================================================================
-- Retirada de `volunteer_category.delivery_prep`.
--
-- La marca del montaje se mudó al punto de recogida
-- (`dev-docs/schema/node-delivery-prep.sql`), donde tener uno, ninguno o todos
-- marcados es válido. En el tipo de trabajo no lo era: servía para señalar una
-- sola cosa en toda la asociación, y aun así permitía cero —panel mudo— y dos
-- —panel señalando a quien friega el suelo—.
--
-- ⚠️⚠️ ESTE VA DESPUÉS DEL CÓDIGO, al revés que casi todos los de esta carpeta,
-- Y NO ES UNA SUGERENCIA: se ejecutó antes de tiempo el 2026-09-03 y dejó el
-- escaparate en 500 con
--
--     SQLSTATE[42S22]: Column not found: 1054
--     Unknown column 't0.delivery_prep' in 'field list'
--
-- en TODA pantalla que cargara un área de voluntariado. La regla de «el SQL
-- antes del código» vale para AÑADIR: una columna nueva que el código viejo
-- ignora no molesta a nadie. Con un DROP el orden se INVIERTE, porque una
-- columna que la entidad sigue mapeando y ya no existe tumba la aplicación.
--
-- Y OJO CON QUÉ CÓDIGO CORRE EN CADA BASE, que es lo que falló: en local, `db`
-- la sirve el árbol principal —hoy la rama `pruebas`—, no la rama donde se está
-- trabajando. Que la entidad esté limpia en tu rama no basta: tiene que estarlo
-- en la rama que sirve esa base.
--
-- Orden correcto, entorno por entorno: subir el código que ya no mapea la
-- columna, comprobar que las pantallas de voluntariado cargan, y entonces
-- ejecutar esto. En producción, entre las dos cosas hay un mirror por FTP: si se
-- invierte, voluntariado se queda caído todo ese rato.
--
-- NO HAY DATO QUE MIGRAR, y está comprobado, no supuesto: en `db` las cuatro
-- áreas tienen la columna a 0, en `db_prod_snapshot` la tabla está vacía, y en
-- producción el módulo de voluntariado se crea desde cero con la migración del
-- 2026-08-31. Nadie ha llegado a marcar nunca esa casilla.
--
-- Aun así, la comprobación de abajo se ejecuta ANTES en cada entorno. Si
-- devuelve algo, hay un área marcada que alguien usó y toca mirar qué punto
-- convocaba antes de borrar nada.
--
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod, cada una
-- DESPUÉS de que su código deje de mapear la columna. A 2026-09-03 las tres la
-- tienen puesta a propósito: se quitó, rompió el escaparate y se devolvió.
-- ============================================================================

-- 1) Comprobación previa. Tiene que devolver CERO filas.
SELECT id, name FROM volunteer_category WHERE delivery_prep = 1;

-- 2) Sólo si lo anterior salió vacío.
ALTER TABLE volunteer_category DROP delivery_prep;


-- ============================================================================
-- VERIFICACIÓN · las ocho cosas que el código espera
--
-- Las ocho columnas deben decir OK. Si alguna dice FALTA, el módulo que la use
-- dará 500 en cuanto suba el código.
-- ============================================================================

SELECT
  (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'volunteer_shift')            AS volunteer_shift,
  (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'volunteer_place')            AS volunteer_place,
  (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'partner_delivery_shift'
       AND column_name = 'accumulated_to_basket_id')                               AS accumulated_to,
  (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'node'
       AND column_name = 'delivery_prep')                                          AS node_delivery_prep,
  (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'volunteer_offer'
       AND column_name = 'routine')                                                AS offer_routine,
  (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'volunteer_call'
       AND column_name = 'shift_id')                                               AS call_shift_id,
  (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'fos_user'
       AND index_name = 'UNIQ_957A64799393F8FE')                                   AS fos_user_unico,
  (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'volunteer_category'
       AND column_name = 'delivery_prep')                                          AS categoria_delivery_prep_debe_ser_0;

-- ⚠️ EL DRIFT QUE VA A SEGUIR SALIENDO, y que NO hay que arreglar aquí. Un
-- `doctrine:schema:update --dump-sql` seguirá proponiendo estas 14 sentencias
-- después de aplicar este fichero. Son cosméticas y llevan meses ahí:
--
--   · Tipos: `egg_period.month_price`, `partner_basket_extra.amount`,
--     `survey.archived`, `volunteer_coordination_log.happened_on`.
--   · `component_key` VIRTUAL vs STORED en `partner_delivery_shift` y
--     `helper_basket_skip` (ver partner-delivery-shift-component-key.sql).
--   · Renombrados de índice a los nombres que genera Doctrine: `setting`,
--     `lar_project`, `lar_offer`, `partner_basket_extra`, `volunteer_offer`.
--   · `DROP INDEX idx_volunteer_offer_delivery_prep`: lo crea el SQL del montaje
--     pero la entidad no lo declara.
--   · `payload` y `repeat_times` a JSON: en MariaDB `JSON` **es** un alias de
--     `longtext` con `CHECK (json_valid(...))`, así que el CHANGE es ruido. En
--     local no sale porque DDEV es MySQL 8, con JSON nativo.
--
-- Se ha comprobado que, tras aplicar este fichero, el drift restante es
-- exactamente ése y ninguna sentencia más.
