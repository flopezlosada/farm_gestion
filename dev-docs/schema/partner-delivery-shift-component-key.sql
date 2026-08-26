-- ============================================================================
-- `partner_delivery_shift`: columna generada `component_key` + los dos índices
-- únicos que se apoyan en ella.
--
-- POR QUÉ EXISTE ESTE FICHERO. El cambio se aplicó A MANO en julio de 2026 (a
-- `db`, `db_prod_snapshot` y `db_test`) y NO se dejó escrito en ninguna parte.
-- Resultado: staging se quedó sin la columna y el 2026-08-26, al probar el
-- congelado allí, el comando murió con
--
--     SQLSTATE[42S22]: Unknown column 'p0_.component_key' in 'SELECT'
--
-- La entidad mapea la columna, así que CUALQUIER consulta de Doctrine sobre
-- `partner_delivery_shift` la pide. Sin ella, el congelado del reparto no puede
-- ejecutarse. Un cambio de esquema que sólo vive en la memoria de quien lo
-- aplicó reaparece como avería en el siguiente entorno.
--
-- QUÉ RESUELVE (incidente del 2026-07-23 en producción). Un socio tenía dos
-- movimientos contradictorios para el mismo reparto, creados con 24 segundos de
-- diferencia por un doble submit, y el calendario daba 500. El UNIQUE sobre
-- `component_id` no lo impedía porque MySQL trata los NULL como distintos, así
-- que dos movimientos de entrega entera (component_id NULL) nunca colisionaban.
-- `component_key = COALESCE(component_id, 0)` les da a todos la misma clave 0 y
-- los hace chocar, mientras los de componente (verdura, huevos) siguen
-- distintos. El estado ilegal pasa a ser irrepresentable en la base de datos, en
-- vez de depender de un `if` que pierde las carreras.
--
-- VIRTUAL Y NO STORED, a propósito: STORED falla con ERROR 1215 porque el
-- rebuild de la tabla choca con las cuatro claves ajenas ON DELETE CASCADE.
-- VIRTUAL se añade sin rebuild (INSTANT) y en MySQL 8 se indexa igual.
--
-- ORDEN: los índices viejos van FUERA antes de crear los nuevos, porque llevan
-- los mismos nombres.
-- ============================================================================


-- PASO 0 — ANTES DE NADA: ¿hay filas que violarían el nuevo único?
-- Si esto devuelve alguna fila, el ALTER de más abajo fallará. Son los
-- duplicados contradictorios del incidente: hay que decidir cuál se queda y
-- borrar el otro (en julio se borraron las dos filas del socio afectado y se
-- reconcilió con "Reiniciar el mes" desde la pantalla).
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


-- PASO 1 — retirar los índices únicos viejos (van sobre `component_id`).
-- Si en ese entorno no existen, MySQL da error 1091 y se puede ignorar.
ALTER TABLE partner_delivery_shift DROP INDEX uniq_partner_from_basket;
ALTER TABLE partner_delivery_shift DROP INDEX uniq_partner_to_basket;


-- PASO 2 — la columna generada.
ALTER TABLE partner_delivery_shift
  ADD COLUMN component_key INT GENERATED ALWAYS AS (COALESCE(component_id, 0)) VIRTUAL;


-- PASO 3 — los únicos, ahora sobre la clave generada.
ALTER TABLE partner_delivery_shift
  ADD UNIQUE INDEX uniq_partner_from_basket (partner_id, from_basket_id, component_key);
ALTER TABLE partner_delivery_shift
  ADD UNIQUE INDEX uniq_partner_to_basket (partner_id, to_basket_id, component_key);


-- PASO 4 — comprobación. Debe salir la columna VIRTUAL GENERATED y los dos
-- únicos incluyendo `component_key`.
SELECT COLUMN_NAME, EXTRA, GENERATION_EXPRESSION
FROM information_schema.COLUMNS
WHERE TABLE_NAME = 'partner_delivery_shift' AND COLUMN_NAME = 'component_key';

SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columnas
FROM information_schema.STATISTICS
WHERE TABLE_NAME = 'partner_delivery_shift' AND NON_UNIQUE = 0
GROUP BY INDEX_NAME;


-- NOTA: `doctrine:schema:update --dump-sql` reemite siempre un CHANGE sobre
-- `component_key` porque DBAL no compara la expresión de las columnas
-- generadas. Es cosmético. NUNCA correr `schema:update --force` en este
-- proyecto: arrastraría el drift de índices preexistente.
