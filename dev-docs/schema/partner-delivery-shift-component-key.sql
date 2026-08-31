-- ============================================================================
--  🛑 NO IMPORTAR ESTE FICHERO DE UN TIRÓN. 🛑
--
--  No es un script: es un diagnóstico y tres remedios distintos, y sólo uno de
--  los tres aplica a cada base de datos. Importarlo entero en phpMyAdmin
--  ejecuta el remedio equivocado hasta que algo falla. Ya pasó el 2026-08-26
--  contra PRODUCCIÓN: se salvó de milagro porque phpMyAdmin para en el primer
--  error y el primer DROP falló.
--
--  Se usa así: ejecutar el PASO 1, mirar el resultado, y correr SÓLO el bloque
--  que corresponda.
-- ============================================================================
--
-- `partner_delivery_shift`: columna generada `component_key` + los dos índices
-- únicos que se apoyan en ella.
--
-- QUÉ RESUELVE (incidente del 2026-07-23 en producción). Un socio tenía dos
-- movimientos contradictorios para el mismo reparto, creados con 24 segundos de
-- diferencia por un doble submit, y su calendario daba 500. El UNIQUE sobre
-- `component_id` no lo impedía porque MySQL trata los NULL como distintos, así
-- que dos movimientos de entrega entera (`component_id` NULL) nunca chocaban.
-- `component_key = COALESCE(component_id, 0)` les da a todos la misma clave 0 y
-- los hace colisionar, mientras los de componente (verdura, huevos) siguen
-- distintos. El estado ilegal pasa a ser irrepresentable en la base de datos, en
-- vez de depender de un `if` que pierde las carreras.
--
-- POR QUÉ EXISTE ESTE FICHERO. El cambio se aplicó A MANO en julio de 2026 y no
-- se dejó escrito. Un mes después, los cinco entornos estaban en TRES estados
-- distintos: los locales al día, producción con la columna pero SIN los únicos
-- (o sea, mes y medio sin la protección que el fix venía a poner), y staging con
-- los únicos viejos y sin columna — donde reventó el congelado del reparto. Un
-- cambio de esquema que sólo vive en la memoria de quien lo aplicó reaparece
-- como avería en el siguiente entorno.
--
-- VIRTUAL Y NO STORED, a propósito: STORED falla con ERROR 1215 porque el
-- rebuild de la tabla choca con las cuatro claves ajenas ON DELETE CASCADE.
-- VIRTUAL se añade sin rebuild (INSTANT) y en MySQL 8 se indexa igual.


-- ============================================================================
-- PASO 1 — DIAGNÓSTICO. Ejecutar SIEMPRE esto primero, y nada más.
--
-- Sustituir 'NOMBRE_DE_LA_BASE' por el nombre literal: 'gestioncsa' (prod),
-- 'csastaging' (staging), 'db', 'db_prod_snapshot', 'db_test'.
--
-- ⚠️ NO usar `DATABASE()` aquí: en phpMyAdmin, navegando por information_schema
-- la base "actual" es information_schema y la consulta devuelve cero filas SIN
-- error, que parece "no hay nada" y es mentira. Nombre literal siempre.
--
-- ⚠️ Y comprobar que la base es la que crees: en este hosting hay una llamada
-- `csavegadejarama` que NO es producción. Prueba rápida:
-- `SELECT COUNT(*) FROM <base>.weekly_basket;` debe dar miles de filas.
-- ============================================================================

SELECT COLUMN_NAME, EXTRA, GENERATION_EXPRESSION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'NOMBRE_DE_LA_BASE'
  AND TABLE_NAME = 'partner_delivery_shift'
  AND COLUMN_NAME = 'component_key';

SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columnas, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'NOMBRE_DE_LA_BASE'
  AND TABLE_NAME = 'partner_delivery_shift'
GROUP BY INDEX_NAME, NON_UNIQUE;


-- ============================================================================
-- PASO 2 — DUPLICADOS. Ejecutar también siempre, salvo que el diagnóstico diga
-- que la base ya está al día (caso C).
--
-- Hay que mirarlo aunque existan los únicos viejos, porque ésos NO protegen el
-- caso que importa: con `component_id` NULL, MySQL permite repetidos. Si alguna
-- de las dos consultas devuelve filas, el ALTER de más abajo FALLARÁ.
-- ============================================================================

SELECT partner_id, from_basket_id, COALESCE(component_id, 0) AS ck, COUNT(*) AS n
FROM partner_delivery_shift
WHERE from_basket_id IS NOT NULL
GROUP BY partner_id, from_basket_id, ck
HAVING n > 1;

SELECT partner_id, to_basket_id, COALESCE(component_id, 0) AS ck, COUNT(*) AS n
FROM partner_delivery_shift
WHERE to_basket_id IS NOT NULL
GROUP BY partner_id, to_basket_id, ck
HAVING n > 1;

-- Si salen filas, mirar QUÉ dicen antes de borrar nada:
--   SELECT id, partner_id, from_basket_id, to_basket_id, component_id
--   FROM partner_delivery_shift
--   WHERE partner_id = <X> AND from_basket_id = <Y> ORDER BY id;
--
-- `to_basket_id` NULL significa "no recoge" (PartnerDeliveryShift::isSkip()).
-- Dos filas IDÉNTICAS son el mismo intent duplicado por un doble submit: no hay
-- nada que decidir, se borra la de id mayor. Dos filas que se CONTRADICEN (una
-- mueve y otra salta) son el caso de julio: hay que decidir cuál vale, borrar
-- las dos y reconciliar con "Reiniciar el mes" desde la pantalla del socio.


-- ============================================================================
-- CASO A — falta la columna Y están los únicos VIEJOS sobre `component_id`.
-- Es el estado original. (Staging, 2026-08-26.)
-- Los viejos van fuera ANTES, porque los nuevos llevan los mismos nombres.
-- ============================================================================

-- ALTER TABLE partner_delivery_shift DROP INDEX uniq_partner_from_basket;
-- ALTER TABLE partner_delivery_shift DROP INDEX uniq_partner_to_basket;
--
-- ALTER TABLE partner_delivery_shift
--   ADD COLUMN component_key INT GENERATED ALWAYS AS (COALESCE(component_id, 0)) VIRTUAL;
--
-- ALTER TABLE partner_delivery_shift
--   ADD UNIQUE INDEX uniq_partner_from_basket (partner_id, from_basket_id, component_key);
-- ALTER TABLE partner_delivery_shift
--   ADD UNIQUE INDEX uniq_partner_to_basket (partner_id, to_basket_id, component_key);


-- ============================================================================
-- CASO B — la columna YA está, pero NO hay ningún índice único aparte de
-- PRIMARY. Media migración. (Producción, 2026-08-26: mes y medio así.)
-- Aquí NO hay DROP que hacer: no existe nada que quitar.
-- ============================================================================

-- ALTER TABLE partner_delivery_shift
--   ADD UNIQUE INDEX uniq_partner_from_basket (partner_id, from_basket_id, component_key);
-- ALTER TABLE partner_delivery_shift
--   ADD UNIQUE INDEX uniq_partner_to_basket (partner_id, to_basket_id, component_key);


-- ============================================================================
-- CASO C — la columna está y los dos únicos ya van sobre `component_key`.
-- Nada que hacer. (Los tres entornos locales.)
-- ============================================================================


-- ============================================================================
-- VERIFICACIÓN FINAL — el diagnóstico del PASO 1 debe devolver ahora la columna
-- como `VIRTUAL GENERATED` con expresión `coalesce(component_id,0)`, y los dos
-- únicos incluyendo `component_key`.
--
-- NOTA: `doctrine:schema:update --dump-sql` reemite siempre un CHANGE sobre
-- `component_key` porque DBAL no compara la expresión de las columnas
-- generadas. Es cosmético. NUNCA correr `schema:update --force` en este
-- proyecto: arrastraría el drift de índices preexistente.
-- ============================================================================
