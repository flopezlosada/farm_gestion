-- ============================================================================
-- Montaje de cestas con voluntariado — cinco columnas en `node`.
--
-- Cada punto de recogida dice si sus cestas las monta gente voluntaria y
-- cuándo. De aquí sale la convocatoria semanal, que antes había que crear a
-- mano una por una, y de aquí sale también el bloque "quién te prepara la
-- cesta" del panel del socix.
--
-- `delivery_prep_minutes` es cuánto DURA el montaje, de donde sale la hora de
-- fin. Lo que se le reconoce a quien viene sigue en la convocatoria
-- (`volunteer_offer.credited_minutes`), que el módulo separa a propósito porque
-- hay trabajo que vale más de lo que dura.
--
-- POR QUÉ EN EL PUNTO Y NO EN EL TIPO DE TRABAJO. Antes lo decía
-- `volunteer_category.delivery_prep`, una casilla que señalaba UNA cosa en toda
-- la asociación: marcando cero, el panel del socix se quedaba mudo sin decir
-- por qué; marcando dos, señalaba a quien estuviera fregando el suelo. En el
-- punto el booleano es legítimo, porque hay varios y cada uno decide.
--
-- El código (App\Entity\Node) MAPEA estas columnas: hay que añadirlas a la BBDD
-- ANTES de desplegar el código. `node` se lee en el calendario de reparto, en
-- los listados y en la web pública, así que si el código llega primero se cae
-- la aplicación entera, no una pantalla suelta.
--
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod.
-- Sin datos personales; idempotencia no nativa: correr una sola vez por entorno.
--
-- Después, EN LA WEB (Socixs › Reparto › editar el punto), marcar "Este punto
-- monta las cestas con voluntariado" donde corresponda —hoy sólo Torremocha— y
-- decir a qué hora. Sin marcar nada no cambia nada: todos los puntos nacen
-- apagados, que es el estado de partida correcto.
-- ============================================================================

ALTER TABLE node
    ADD delivery_prep TINYINT(1) DEFAULT 0 NOT NULL,
    ADD delivery_prep_slots INT DEFAULT NULL,
    ADD delivery_prep_time TIME DEFAULT NULL,
    ADD delivery_prep_minutes INT DEFAULT NULL,
    ADD delivery_prep_day_offset SMALLINT DEFAULT 0 NOT NULL;
