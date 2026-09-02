-- ============================================================================
-- Retirada de `volunteer_category.delivery_prep`.
--
-- La marca del montaje se mudó al punto de recogida
-- (`dev-docs/schema/node-delivery-prep.sql`), donde tener uno, ninguno o todos
-- marcados es válido. En el tipo de trabajo no lo era: servía para señalar una
-- sola cosa en toda la asociación, y aun así permitía cero —panel mudo— y dos
-- —panel señalando a quien friega el suelo—.
--
-- ⚠️ ESTE VA DESPUÉS DEL CÓDIGO, al revés que casi todos los de esta carpeta.
-- Mientras el código viejo siga arriba, la columna se lee; borrarla antes
-- tumbaría voluntariado entero. Orden: subir el código, comprobar que las
-- pantallas de voluntariado cargan, y entonces ejecutar esto.
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
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod.
-- ============================================================================

-- 1) Comprobación previa. Tiene que devolver CERO filas.
SELECT id, name FROM volunteer_category WHERE delivery_prep = 1;

-- 2) Sólo si lo anterior salió vacío.
ALTER TABLE volunteer_category DROP delivery_prep;
