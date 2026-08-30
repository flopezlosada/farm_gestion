-- ============================================================================
-- Preferencias de avisos por socix — tabla notification_opt_out.
--
-- Guarda lo que un socix NO quiere recibir: una fila por (socix, tema, canal).
-- SIN FILA = LO QUIERE, y ése es el motivo de que la tabla sea de opt-outs y no
-- de opt-ins: hoy todo el mundo recibe todo, así que una tabla de "síes"
-- obligaría a sembrar una fila por cada socix, tema y canal antes de desplegar,
-- o los avisos dejarían de salir en silencio para toda la asociación.
--
-- `topic` y `channel` son cadenas y no claves foráneas a propósito: el catálogo
-- de temas vive en el código (App\Service\Notification\NotificationTopic), así
-- que añadir "grupo de consumo" no obliga a migrar esta tabla ni a sembrar nada.
--
-- Aplicar a las TRES bases de trabajo (db, db_prod_snapshot, db_test) y en
-- producción ANTES de subir el código: la pantalla de avisos la lee al cargar.
-- ============================================================================

CREATE TABLE IF NOT EXISTS notification_opt_out (
    id INT AUTO_INCREMENT NOT NULL,
    partner_id INT NOT NULL,
    topic VARCHAR(32) NOT NULL,
    channel VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_notification_opt_out (partner_id, topic, channel),
    KEY idx_notification_opt_out_partner (partner_id),
    CONSTRAINT fk_notification_opt_out_partner
        FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
