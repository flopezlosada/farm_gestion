-- ============================================================================
--  `partner_delivery_shift`: columna `accumulated_to_basket_id`.
--
--  🔴 ORDEN DE DESPLIEGUE: ESTE SQL **ANTES** DEL CÓDIGO.
--  La entidad mapea la columna nueva; sin ella, cualquier lectura de un
--  PartnerDeliveryShift revienta y eso es TODO el calendario de reparto (gestor
--  y socix) más el generador semanal. Con la columna puesta y el código viejo
--  todavía arriba no pasa nada: nadie la escribe y queda a NULL, que es
--  exactamente el comportamiento actual.
--
--  Aditivo y reversible. No reconstruye la tabla (ADD COLUMN nullable es
--  INSTANT en MySQL 8) y no toca ningún índice existente.
-- ============================================================================
--
-- QUÉ RESUELVE. "Trasladar sumando" lleva la cesta de una semana a un día en el
-- que el socix ya recoge: ese día pasa a llevar dos cestas. Como un día no puede
-- tener dos WeeklyBaskets del mismo socix, la segunda vive como cesta extra
-- (`partner_basket_extra`) y la semana de origen se vacía con un intent SIN
-- destino (`to_basket_id` NULL) — el mismo que usa "no recoge".
--
-- Y ahí estaba el problema: ese intent era INDISTINGUIBLE de un "no recoge", así
-- que el calendario pintaba la cesta en la papelera como pendiente mientras ya
-- estaba colocada (y sumada) en el otro día. La cesta se contaba DOS VECES, y la
-- tarjeta de la papelera invitaba a recuperarla → una cesta de más en el listado
-- impreso. `accumulated_to_basket_id` dice a qué semana se fue: con valor, la
-- cesta está colocada y no sale en la papelera; a NULL, es un "no recoge" de
-- verdad y sigue pendiente.
--
-- Es además el hilo para DESHACER el traslado en un gesto ("Deshacer el
-- traslado" en el día destino), que antes había que hacer en dos pasos
-- (quitar la extra + recuperar la del origen) y que hecho a medias dejaba una
-- cesta de más o de menos.


-- ============================================================================
-- PASO 1 — DIAGNÓSTICO. ¿Está ya la columna?
--
-- Sustituir 'NOMBRE_DE_LA_BASE' por el nombre literal: 'gestioncsa' (prod),
-- 'csastaging' (staging), 'db', 'db_prod_snapshot', 'db_test'.
--
-- ⚠️ NO usar `DATABASE()`: navegando information_schema en phpMyAdmin la base
-- "actual" es information_schema y la consulta devuelve cero filas SIN error.
--
-- ⚠️ Comprobar que la base es la que crees: en este hosting hay una llamada
-- `csavegadejarama` que NO es producción. Prueba rápida:
-- `SELECT COUNT(*) FROM <base>.weekly_basket;` debe dar miles de filas.
-- ============================================================================

SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'NOMBRE_DE_LA_BASE'
  AND TABLE_NAME = 'partner_delivery_shift'
  AND COLUMN_NAME = 'accumulated_to_basket_id';

-- Cero filas → aplicar el PASO 2. Una fila → ya está, saltar al PASO 3.


-- ============================================================================
-- PASO 2 — LA COLUMNA. Los tres statements, en este orden.
--
-- El nombre de la clave ajena NO es decorativo: es el que genera Doctrine
-- (`FK_B49754EC15F19D1E`, sacado de `doctrine:schema:update --dump-sql`). Con
-- otro nombre, cada dump-sql futuro propondría recrearla y el drift de esquema
-- vuelve a crecer — la lección de `node_sheet_recipient` (#111).
--
-- ON DELETE CASCADE igual que `from_basket_id` / `to_basket_id`: si se borra un
-- Basket, sus intents se van con él.
-- ============================================================================

ALTER TABLE partner_delivery_shift
  ADD accumulated_to_basket_id INT DEFAULT NULL;

ALTER TABLE partner_delivery_shift
  ADD CONSTRAINT FK_B49754EC15F19D1E FOREIGN KEY (accumulated_to_basket_id)
  REFERENCES basket (id) ON DELETE CASCADE;

CREATE INDEX idx_pds_accumulated_to ON partner_delivery_shift (accumulated_to_basket_id);


-- ============================================================================
-- PASO 3 — DATOS YA EXISTENTES. Los traslados sumando hechos ANTES de este
-- cambio quedan con la columna a NULL, o sea, contados como "no recoge": su
-- cesta sigue apareciendo en la papelera aunque esté colocada en otro día. Hay
-- que marcarlos, o el bug sigue vivo para ellos.
--
-- Cómo se reconocen: `AccumulatingMove` hace las dos escrituras en la misma
-- transacción, así que el intent sin destino y la cesta extra del destino
-- comparten `created` AL SEGUNDO para el mismo socix. Esa coincidencia es la
-- firma; un "no recoge" normal no la tiene.
--
-- Solo importan las semanas FUTURAS: un traslado cuya semana de origen ya pasó
-- no se puede recuperar de la papelera (el calendario es solo lectura ahí), así
-- que no puede producir la cesta de más.
-- ============================================================================

-- 3a. LISTAR los candidatos. Mirar el resultado antes de tocar nada.
SELECT s.id            AS shift_id,
       s.partner_id,
       s.from_basket_id,
       fb.date         AS semana_origen,
       e.basket_id     AS destino_basket_id,
       db.date         AS semana_destino,
       s.created
FROM partner_delivery_shift s
JOIN basket fb ON fb.id = s.from_basket_id
JOIN partner_basket_extra e
       ON e.partner_id = s.partner_id
      AND e.created = s.created
JOIN basket db ON db.id = e.basket_id
WHERE s.to_basket_id IS NULL
  AND s.component_id IS NULL
  AND s.accumulated_to_basket_id IS NULL
  AND fb.date >= CURDATE()
GROUP BY s.id, s.partner_id, s.from_basket_id, fb.date, e.basket_id, db.date, s.created
ORDER BY fb.date;

-- En el dump de producción del 2026-09-04 esto devuelve UNA fila:
--   shift 116 · from_basket 510 (2026-09-04) · destino 512 (2026-09-18)
-- Es un traslado real hecho desde gestión el 2026-09-03 a las 23:42:56.

-- 3b. MARCARLOS. Mismo criterio que la consulta de arriba, aplicado con UPDATE.
--     Descomentar y ejecutar SOLO después de mirar el listado de 3a.
--
-- UPDATE partner_delivery_shift s
-- JOIN basket fb ON fb.id = s.from_basket_id
-- JOIN (
--         SELECT e.partner_id, e.created, MIN(e.basket_id) AS basket_id
--         FROM partner_basket_extra e
--         GROUP BY e.partner_id, e.created
--         HAVING COUNT(DISTINCT e.basket_id) = 1
--      ) x ON x.partner_id = s.partner_id AND x.created = s.created
-- SET s.accumulated_to_basket_id = x.basket_id
-- WHERE s.to_basket_id IS NULL
--   AND s.component_id IS NULL
--   AND s.accumulated_to_basket_id IS NULL
--   AND fb.date >= CURDATE();
--
-- (El subselect exige que todas las líneas de extra de ese instante apunten a
-- la MISMA semana. Si no —dos añadidos distintos en el mismo segundo, que no se
-- ha visto nunca—, la fila se queda sin marcar y hay que resolverla a mano en
-- vez de adivinar el destino.)

-- 3c. Alternativa a mano si 3a devuelve poco (el caso de producción). Más
--     seguro que el UPDATE con JOIN: se ve exactamente qué se escribe.
--
-- UPDATE partner_delivery_shift SET accumulated_to_basket_id = 512 WHERE id = 116;


-- ============================================================================
-- VERIFICACIÓN FINAL
-- ============================================================================

-- La columna existe y la clave ajena tiene el nombre de Doctrine:
-- SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
-- FROM information_schema.KEY_COLUMN_USAGE
-- WHERE TABLE_SCHEMA = 'NOMBRE_DE_LA_BASE'
--   AND TABLE_NAME = 'partner_delivery_shift'
--   AND COLUMN_NAME = 'accumulated_to_basket_id';

-- Ya no queda ningún traslado futuro sin marcar (3a debe devolver cero filas).

-- Y en pantalla: el calendario del socix cuya cesta se trasladó debe mostrar el
-- día de origen VACÍO y la papelera SIN esa tarjeta, y el día destino con dos
-- cestas y el botón "Deshacer el traslado".

-- NOTA: `doctrine:schema:update --dump-sql` seguirá reemitiendo el CHANGE de
-- `component_key` y la FK de `component_id`. Es drift preexistente y cosmético
-- (ver partner-delivery-shift-component-key.sql). NUNCA correr
-- `schema:update --force` en este proyecto.
