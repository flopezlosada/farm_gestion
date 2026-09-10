-- ============================================================================
-- Bitácora de avisos enviados (DDL).
--
-- Tabla nueva `notification_log`: una fila por aviso que la aplicación ha
-- intentado entregar a alguien, por correo o al móvil, salga bien o mal. Se
-- escribe en el TRANSPORTE (RecordingMailer para el correo, PushSender para el
-- push), así que cubre todos los avisos existentes y los que se añadan, sin que
-- quien envía tenga que acordarse de apuntar nada.
--
-- No sustituye a `emitted_effect`, que sigue haciendo su trabajo: aquel evita
-- duplicados y por eso tiene una sola fila por aviso y RETIRA el apunte cuando
-- el envío falla. Con esas reglas no se puede reconstruir qué pasó — los fallos
-- no dejan rastro y los reenvíos no se ven. Esta tabla es el registro; aquella,
-- el guardián.
--
-- ANTES de aplicarlo, contrástalo con lo que Doctrine espera:
--   ddev exec bin/console doctrine:schema:update --dump-sql | grep notification_log
-- Y NUNCA `doctrine:schema:update --force`: arrastraría el drift de índices
-- preexistente (ver CLAUDE.md / memoria schema_drift_anotaciones_vs_db).
--
-- Aplicar a las TRES BBDD de trabajo: db (sandbox), db_prod_snapshot (golden)
-- y db_test.
--   ddev mysql db               < dev-docs/schema/schema-notification-log.sql
--   ddev mysql db_prod_snapshot < dev-docs/schema/schema-notification-log.sql   # tras esto, bin/db-backup
--   ddev mysql db_test          < dev-docs/schema/schema-notification-log.sql
--
-- En PRODUCCIÓN, a mano por phpMyAdmin. La tabla nace vacía.
--
-- ORDEN RESPECTO AL CÓDIGO: 🔴 LA TABLA VA ANTES. La pantalla /gestion/avisos y
-- la ficha del socix la consultan al pintarse, así que con el código desplegado
-- y sin tabla esas dos pantallas dan 500. El registro en sí es tolerante (si
-- falla el apunte, el aviso se manda igual y se anota en el log de la app: un
-- correo jamás se pierde por no poder registrarlo), pero las pantallas no.
--
-- Las dos claves ajenas van con ON DELETE SET NULL a propósito: borrar a una
-- persona o purgar ejecuciones antiguas no debe llevarse por delante el
-- historial de lo que se envió, sólo el enlace. Si en producción la creación de
-- las FK fallara por un desajuste de tipos con `partner` o `cron_run`, se puede
-- crear la tabla sin ellas — pero entonces hay que saber que una fila puede
-- quedar apuntando a un id que ya no existe.
-- ============================================================================

CREATE TABLE notification_log (
    id INT AUTO_INCREMENT NOT NULL,
    sent_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    channel VARCHAR(10) NOT NULL,
    kind VARCHAR(60) NOT NULL,
    subject VARCHAR(255) DEFAULT NULL,
    partner_id INT DEFAULT NULL,
    target VARCHAR(255) NOT NULL,
    status VARCHAR(12) NOT NULL,
    error VARCHAR(255) DEFAULT NULL,
    task_key VARCHAR(100) DEFAULT NULL,
    cron_run_id INT DEFAULT NULL,
    INDEX IDX_notif_log_sent (sent_at),
    INDEX IDX_notif_log_partner (partner_id, sent_at),
    INDEX IDX_notif_log_kind (kind, sent_at),
    INDEX IDX_notif_log_target (target),
    INDEX IDX_notif_log_run (cron_run_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE notification_log
    ADD CONSTRAINT FK_notif_log_partner FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE SET NULL;

ALTER TABLE notification_log
    ADD CONSTRAINT FK_notif_log_cron_run FOREIGN KEY (cron_run_id) REFERENCES cron_run (id) ON DELETE SET NULL;
