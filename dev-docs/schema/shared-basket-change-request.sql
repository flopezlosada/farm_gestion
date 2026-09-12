-- ============================================================
-- Cestas compartidas: un hogar pide un cambio y el otro lo acepta o lo rechaza.
--
-- POR QUÉ. Dos familias se reparten UNA cesta física, así que su día y su punto de
-- recogida son datos de la pareja y no de cada hogar (leyes L5 y L22). Hasta ahora
-- eso se resolvía prohibiendo el cambio a todo el mundo —también a administración—;
-- esta tabla es la otra salida: pedirlo, y que lo aplique la conformidad del otro.
--
-- LA FILA NO CAMBIA EL REPARTO. Mientras está `pending` no hay nada movido: el
-- cambio se aplica al aceptar, revalidando. Por eso no hace falta ninguna cascada
-- aquí ni ninguna tarea que barra lo caducado — una petición sobre una semana que ya
-- pasó se ignora al leerla (`isActionable()`), no se marca.
--
-- ORDEN DE EJECUCIÓN: este fichero ANTES de subir el código. Con el código nuevo
-- contra el esquema viejo, el panel del socix y el calendario del gestor dan 500 en
-- cuanto tocan una cesta compartida (Doctrine busca `shared_basket_change_request`).
-- Con el esquema nuevo y el código viejo no pasa nada: es una tabla que nadie mira.
--
-- DATOS: ninguno que migrar. Nace vacía.
--
-- Aplicar a las TRES bases: db, db_prod_snapshot y db_test.
--
-- LOS NOMBRES DE ÍNDICES Y CLAVES AJENAS SON LOS QUE GENERA DOCTRINE
-- (`IDX_<hash>` / `FK_<hash>`), no unos legibles: con nombres propios,
-- `doctrine:schema:update --dump-sql` propondría renombrarlos en cada ejecución
-- para siempre, y ese ruido tapa el drift de esquema de verdad. Los hashes de aquí
-- están calculados con la fórmula de Doctrine y verificados contra
-- `partner_node_override`, que ya está en producción.
-- ============================================================

CREATE TABLE IF NOT EXISTS `shared_basket_change_request` (
  `id` INT AUTO_INCREMENT NOT NULL,
  -- Hogar que pide y hogar que decide. Los dos son socixs principales de su familia:
  -- la cesta cuelga de ellos (PartnerBasketShare), y son los que se enlazan por
  -- `partner.share_partner_id`.
  `requester_id` INT NOT NULL,
  `counterpart_id` INT NOT NULL,
  -- move | relocate | modality. Ver SharedBasketChangeRequest::KIND_*.
  `kind` VARCHAR(20) NOT NULL,
  -- Semana afectada; NULL en un cambio de modalidad, que no es de una semana.
  `basket_id` INT DEFAULT NULL,
  -- Semana destino de un cambio de día.
  `to_basket_id` INT DEFAULT NULL,
  -- Punto de recogida destino de un traslado.
  `target_group_id` INT DEFAULT NULL,
  -- Lo pedido en un cambio de modalidad, tal cual se eligió. No se aplica desde
  -- aquí: es lo que administración lee para saber qué acordaron los dos hogares.
  `payload` JSON DEFAULT NULL,
  -- Recado de quien pide. Es lo que convierte "del 19 al 26" en algo que se puede
  -- contestar sin llamar por teléfono.
  `note` VARCHAR(500) DEFAULT NULL,
  -- pending | accepted | rejected | cancelled. Ver STATUS_*.
  `status` VARCHAR(12) NOT NULL,
  `created_at` DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `decided_at` DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  -- Los dos índices que sostienen las consultas de la pantalla: "qué me toca
  -- contestar" y "qué pedí yo", siempre con el estado en la misma consulta.
  KEY `idx_sbcr_counterpart_status` (`counterpart_id`, `status`),
  KEY `idx_sbcr_requester_status` (`requester_id`, `status`),
  KEY `idx_sbcr_basket` (`basket_id`),
  KEY `IDX_B9CCF87E2A422C23` (`to_basket_id`),
  KEY `IDX_B9CCF87E24FF092E` (`target_group_id`),
  PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- ON DELETE CASCADE en las cinco: si se borra un socix, una semana o un punto, la
-- petición que hablaba de ellos deja de tener sentido. Es lo mismo que hacen
-- `partner_node_override` y `partner_delivery_shift`, que también describen una
-- desviación concreta y no un histórico — el histórico vive en `partner_event`.
ALTER TABLE `shared_basket_change_request`
  ADD CONSTRAINT `FK_B9CCF87EED442CF4` FOREIGN KEY (`requester_id`) REFERENCES `partner` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `FK_B9CCF87E606374F2` FOREIGN KEY (`counterpart_id`) REFERENCES `partner` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `FK_B9CCF87E1BE1FB52` FOREIGN KEY (`basket_id`) REFERENCES `basket` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `FK_B9CCF87E2A422C23` FOREIGN KEY (`to_basket_id`) REFERENCES `basket` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `FK_B9CCF87E24FF092E` FOREIGN KEY (`target_group_id`) REFERENCES `weekly_basket_group` (`id`) ON DELETE CASCADE;
