-- ============================================================================
-- `helper_basket_skip`: columna `component_id` + la generada `component_key`,
-- y el único pasa a incluirla.
--
-- Este fichero SÍ se puede pegar entero: es un solo camino, sin variantes por
-- entorno. Y si la base ya está al día, el PASO 1 falla con ERROR 1060 (columna
-- duplicada) y phpMyAdmin para ahí, ANTES de tocar ningún índice. Ese orden es
-- deliberado — ver la nota al final.
--
-- QUÉ RESUELVE. Hasta ahora un voluntario del albergue sólo podía saltar la
-- cesta ENTERA de una semana: la fila (helper_id, skip_date) no sabía distinguir
-- componentes. Cuando la granja se queda sin huevos y hay que retirarlos de un
-- punto de recogida completo, al voluntario había que dejarlo fuera del lote (y
-- entonces el listado le seguía poniendo docenas que no existen) o quitarle la
-- cesta entera (y entonces se quedaba también sin verdura). Con `component_id`
-- nullable, null sigue siendo "no recoge nada" y con valor se cae sólo ese
-- componente.
--
-- POR QUÉ `component_key`. Mismo motivo que en `partner_delivery_shift` (ver
-- partner-delivery-shift-component-key.sql): MySQL trata los NULL como
-- distintos, así que un UNIQUE sobre `component_id` no impediría dos saltos de
-- cesta entera el mismo día. `COALESCE(component_id, 0)` les da a todos la misma
-- clave 0 y los hace colisionar, mientras los de componente siguen distintos.
--
-- VIRTUAL Y NO STORED, igual que allí: se añade sin rebuild de tabla (INSTANT),
-- no choca con las claves ajenas ON DELETE CASCADE, y MySQL 8 la indexa igual.
-- ============================================================================


-- ============================================================================
-- PASO 1 — La columna. Si la base ya está migrada, esto falla y aquí acaba todo
-- (que es lo que queremos: nada más abajo se ejecuta).
-- ============================================================================

ALTER TABLE helper_basket_skip
  ADD COLUMN component_id INT DEFAULT NULL,
  ADD COLUMN component_key INT GENERATED ALWAYS AS (COALESCE(component_id, 0)) VIRTUAL;


-- ============================================================================
-- PASO 2 — El único, que pasa a incluir la clave del componente. El viejo va
-- fuera primero porque el nuevo lleva el mismo nombre.
--
-- No hace falta buscar duplicados antes: el único nuevo es MÁS laxo que el que
-- sustituye (añade una columna al grupo), así que no puede fallar por filas que
-- el anterior ya permitía.
-- ============================================================================

ALTER TABLE helper_basket_skip DROP INDEX uniq_helper_basket_skip;

ALTER TABLE helper_basket_skip
  ADD UNIQUE INDEX uniq_helper_basket_skip (helper_id, skip_date, component_key);


-- ============================================================================
-- PASO 3 — La clave ajena al catálogo de componentes y su índice.
-- ============================================================================

ALTER TABLE helper_basket_skip
  ADD CONSTRAINT FK_4CB28622E2ABAFFF FOREIGN KEY (component_id)
  REFERENCES basket_component (id) ON DELETE CASCADE;

CREATE INDEX idx_hbs_component ON helper_basket_skip (component_id);


-- ============================================================================
-- VERIFICACIÓN — sustituir 'NOMBRE_DE_LA_BASE' por el nombre literal:
-- 'gestioncsa' (prod), 'csastaging' (staging), 'db', 'db_prod_snapshot', 'db_test'.
--
-- ⚠️ NO usar `DATABASE()`: en phpMyAdmin, navegando por information_schema la
-- base "actual" es information_schema y la consulta devuelve cero filas SIN
-- error, que parece "no hay nada" y es mentira.
--
-- ⚠️ Y comprobar que la base es la que crees: en este hosting hay una llamada
-- `csavegadejarama` que NO es producción.
--
-- Debe devolver `component_key` como VIRTUAL GENERATED con expresión
-- `coalesce(component_id,0)`, y el único incluyendo las tres columnas.
-- ============================================================================

-- SELECT COLUMN_NAME, EXTRA, GENERATION_EXPRESSION
-- FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = 'NOMBRE_DE_LA_BASE'
--   AND TABLE_NAME = 'helper_basket_skip'
--   AND COLUMN_NAME IN ('component_id', 'component_key');
--
-- SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columnas, NON_UNIQUE
-- FROM information_schema.STATISTICS
-- WHERE TABLE_SCHEMA = 'NOMBRE_DE_LA_BASE'
--   AND TABLE_NAME = 'helper_basket_skip'
-- GROUP BY INDEX_NAME, NON_UNIQUE;


-- ============================================================================
-- NOTA. `doctrine:schema:update --dump-sql` reemite siempre un CHANGE sobre las
-- columnas generadas porque DBAL no compara su expresión. Es cosmético. NUNCA
-- correr `schema:update --force` en este proyecto: arrastraría el drift de
-- índices preexistente.
-- ============================================================================
