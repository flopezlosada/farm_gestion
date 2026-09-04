-- ============================================================
-- Voluntariado: la tarea deja de llevar la fecha y nacen los TURNOS.
--
-- POR QUÉ. `volunteer_offer` era la tarea y su momento a la vez, así que repetir
-- un trabajo significaba duplicar la ficha entera por cada fecha. Eso aguanta el
-- reparto —52 al año— y se rompe con todo lo demás: sacar al perro mañana y
-- tarde son 730 filas al año, y corregir una errata en su explicación, 730
-- ediciones. A partir de aquí la tarea dice QUÉ se hace y cómo se repite, y
-- `volunteer_shift` dice CUÁNDO. La gente se apunta a un turno.
--
-- ORDEN DE EJECUCIÓN: este fichero ANTES de subir el código. Con el código nuevo
-- contra el esquema viejo, cualquier pantalla del módulo da 500 (Doctrine busca
-- `volunteer_shift`); con el esquema nuevo y el código viejo, el módulo también
-- falla, pero sólo el módulo — y está detrás de un toggle. Ese es el lado seguro.
--
-- DATOS: se conserva todo. Cada tarea existente se convierte en tarea + un turno
-- con su fecha, y las inscripciones y avisos se repuntan a ese turno. En el
-- snapshot de producción `volunteer_offer` tiene 0 filas, así que en prod esto no
-- migra nada; en las bases de trabajo sí (7 tareas, 8 inscripciones).
--
-- Aplicar a las TRES bases: db, db_prod_snapshot y db_test.
-- ============================================================

-- ------------------------------------------------------------
-- 1. Catálogo de sitios donde se hace voluntariado.
--    El sitio se escribía a mano en cada tarea; con turnos serían cientos de
--    filas con "la nave", "nave" y "Nave" siendo el mismo lugar.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `volunteer_place` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `directions` LONGTEXT DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE INDEX `uniq_volunteer_place_name` (`name`),
  PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- Los cuatro sitios que se usan hoy. Con nombres neutros a propósito: es más
-- fácil renombrar uno que descubrir tres meses después que nadie usó el
-- catálogo porque estaba vacío y todo el mundo escribió el sitio a mano.
INSERT INTO `volunteer_place` (`name`, `active`) VALUES
  ('La huerta', 1),
  ('El local', 1),
  ('El invernadero', 1),
  ('La nave', 1)
ON DUPLICATE KEY UPDATE `name` = `name`;

-- ------------------------------------------------------------
-- 2. Los turnos.
--    UNIQUE (offer_id, starts_at) es lo que hace idempotente al generador:
--    volver a abrir la serie no puede duplicar turnos.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `volunteer_shift` (
  `id` INT AUTO_INCREMENT NOT NULL,
  `offer_id` INT NOT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME DEFAULT NULL,
  `own_slots` INT DEFAULT NULL,
  `own_credited_minutes` INT DEFAULT NULL,
  `guests` INT NOT NULL DEFAULT 0,
  `guests_note` VARCHAR(160) DEFAULT NULL,
  `manual` TINYINT(1) NOT NULL DEFAULT 0,
  `cancelled_at` DATETIME DEFAULT NULL,
  `cancelled_reason` VARCHAR(160) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE INDEX `uniq_volunteer_shift_offer_start` (`offer_id`, `starts_at`),
  INDEX `idx_volunteer_shift_starts_at` (`starts_at`),
  INDEX `idx_volunteer_shift_offer` (`offer_id`),
  PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE `volunteer_shift`
  ADD CONSTRAINT `fk_volunteer_shift_offer`
  FOREIGN KEY (`offer_id`) REFERENCES `volunteer_offer` (`id`) ON DELETE CASCADE;

-- ------------------------------------------------------------
-- 3. Cada tarea existente se convierte en tarea + su turno.
--    `manual` a 1: esa fecha la puso una persona, no una receta, así que la
--    sincronización con la receta no puede retirarla.
-- ------------------------------------------------------------
INSERT INTO `volunteer_shift`
  (`offer_id`, `starts_at`, `ends_at`, `guests`, `guests_note`, `manual`, `created_at`)
SELECT `id`, `starts_at`, `ends_at`, `guests`, `guests_note`, 1, `created_at`
FROM `volunteer_offer`;

-- ------------------------------------------------------------
-- 4. Las inscripciones pasan a colgar del turno.
-- ------------------------------------------------------------
ALTER TABLE `volunteer_signup` ADD `shift_id` INT DEFAULT NULL AFTER `id`;

UPDATE `volunteer_signup` `s`
  JOIN `volunteer_shift` `sh` ON `sh`.`offer_id` = `s`.`offer_id`
SET `s`.`shift_id` = `sh`.`id`;

-- Una inscripción sin turno no significa nada, y con el modelo nuevo no se
-- puede pintar: se queda fuera antes de poner la columna NOT NULL.
DELETE FROM `volunteer_signup` WHERE `shift_id` IS NULL;

ALTER TABLE `volunteer_signup` DROP FOREIGN KEY `FK_1D22E30253C674EE`;
ALTER TABLE `volunteer_signup` DROP INDEX `uniq_volunteer_signup`;
ALTER TABLE `volunteer_signup` DROP INDEX `IDX_1D22E30253C674EE`;
ALTER TABLE `volunteer_signup` DROP `offer_id`;

ALTER TABLE `volunteer_signup` MODIFY `shift_id` INT NOT NULL;
ALTER TABLE `volunteer_signup`
  ADD UNIQUE INDEX `uniq_volunteer_signup` (`shift_id`, `partner_id`),
  ADD INDEX `idx_volunteer_signup_shift` (`shift_id`);
ALTER TABLE `volunteer_signup`
  ADD CONSTRAINT `fk_volunteer_signup_shift`
  FOREIGN KEY (`shift_id`) REFERENCES `volunteer_shift` (`id`) ON DELETE CASCADE;

-- ------------------------------------------------------------
-- 5. Los avisos, igual: se piden para un turno.
--    Con la unicidad por tarea, pedir gente para el reparto del viernes 12
--    gastaba el aviso de TODOS los viernes del año.
-- ------------------------------------------------------------
ALTER TABLE `volunteer_call` ADD `shift_id` INT DEFAULT NULL AFTER `id`;

UPDATE `volunteer_call` `c`
  JOIN `volunteer_shift` `sh` ON `sh`.`offer_id` = `c`.`offer_id`
SET `c`.`shift_id` = `sh`.`id`;

DELETE FROM `volunteer_call` WHERE `shift_id` IS NULL;

ALTER TABLE `volunteer_call` DROP FOREIGN KEY `FK_7A3F3E1253C674EE`;
ALTER TABLE `volunteer_call` DROP INDEX `uniq_volunteer_call_scope`;
ALTER TABLE `volunteer_call` DROP INDEX `IDX_7A3F3E1253C674EE`;
ALTER TABLE `volunteer_call` DROP `offer_id`;

ALTER TABLE `volunteer_call` MODIFY `shift_id` INT NOT NULL;
ALTER TABLE `volunteer_call`
  ADD UNIQUE INDEX `uniq_volunteer_call_scope` (`shift_id`, `scope`),
  ADD INDEX `idx_volunteer_call_shift` (`shift_id`);
ALTER TABLE `volunteer_call`
  ADD CONSTRAINT `fk_volunteer_call_shift`
  FOREIGN KEY (`shift_id`) REFERENCES `volunteer_shift` (`id`) ON DELETE CASCADE;

-- ------------------------------------------------------------
-- 6. La tarea: pierde el momento y gana la receta de repetición.
--
--    La receta SÍ vive en la tarea, al contrario de lo que se hacía antes. Y
--    puede, justamente porque ahora está escrita en un solo sitio: cuando cae
--    un festivo se anula ESE turno y la receta sigue diciendo la verdad ("esto
--    se hace los viernes"), que era el problema de tenerla copiada en cada una
--    de las 52 fichas.
-- ------------------------------------------------------------
ALTER TABLE `volunteer_offer`
  ADD `place_id` INT DEFAULT NULL,
  ADD `place_note` VARCHAR(160) DEFAULT NULL,
  ADD `repeat_type` VARCHAR(16) NOT NULL DEFAULT 'once',
  ADD `repeat_every` INT NOT NULL DEFAULT 1,
  ADD `repeat_weekdays` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:simple_array)',
  ADD `repeat_times` JSON DEFAULT NULL,
  ADD `repeat_from` DATE DEFAULT NULL,
  ADD `repeat_until` DATE DEFAULT NULL;

-- El sitio escrito a mano pasa a ser la PRECISIÓN sobre el sitio; el catálogo
-- se elige después a mano, tarea por tarea. Adivinar a qué fila del catálogo
-- corresponde "parcela de arriba" sería inventarse el dato.
UPDATE `volunteer_offer` SET `place_note` = `place` WHERE `place` IS NOT NULL AND `place` <> '';

-- La receta de lo que ya existe: una sola vez, el día que tenía, con su tramo
-- horario. Es la única lectura honesta de una tarea que se creó sin receta.
UPDATE `volunteer_offer` `o`
  JOIN `volunteer_shift` `sh` ON `sh`.`offer_id` = `o`.`id`
SET
  `o`.`repeat_type` = 'once',
  `o`.`repeat_from` = DATE(`sh`.`starts_at`),
  `o`.`repeat_until` = DATE(`sh`.`starts_at`),
  `o`.`repeat_times` = JSON_ARRAY(JSON_ARRAY(
    TIME_FORMAT(`sh`.`starts_at`, '%H:%i'),
    IF(`sh`.`ends_at` IS NULL, NULL, TIME_FORMAT(`sh`.`ends_at`, '%H:%i'))
  ));

ALTER TABLE `volunteer_offer`
  ADD INDEX `idx_volunteer_offer_place` (`place_id`);
ALTER TABLE `volunteer_offer`
  ADD CONSTRAINT `fk_volunteer_offer_place`
  FOREIGN KEY (`place_id`) REFERENCES `volunteer_place` (`id`) ON DELETE SET NULL;

-- `copied_from_id` se va con el concepto: repetir ya no crea copias de la ficha,
-- abre turnos de la misma tarea. La referencia servía para responder "¿de dónde
-- salieron estas doce?", y ahora la respuesta es que son la misma tarea.
ALTER TABLE `volunteer_offer` DROP FOREIGN KEY `FK_1BB85ACC58B20D94`;
ALTER TABLE `volunteer_offer` DROP INDEX `IDX_1BB85ACC58B20D94`;
ALTER TABLE `volunteer_offer` DROP `copied_from_id`;

ALTER TABLE `volunteer_offer` DROP INDEX `idx_volunteer_offer_starts_at`;
ALTER TABLE `volunteer_offer`
  DROP `starts_at`,
  DROP `ends_at`,
  DROP `place`,
  DROP `guests`,
  DROP `guests_note`;

-- ------------------------------------------------------------
-- 7. Comprobación. Debe devolver una fila por tarea, con su turno, y ninguna
--    inscripción ni aviso huérfanos.
-- ------------------------------------------------------------
-- SELECT
--   (SELECT COUNT(*) FROM volunteer_offer) AS tareas,
--   (SELECT COUNT(*) FROM volunteer_shift) AS turnos,
--   (SELECT COUNT(*) FROM volunteer_signup WHERE shift_id IS NULL) AS apuntes_huerfanos,
--   (SELECT COUNT(*) FROM volunteer_call WHERE shift_id IS NULL) AS avisos_huerfanos;
