-- Avisos push: suscripciones de navegador.
--
-- Fichero aparte del de voluntariado a propósito: el push es infraestructura
-- general de la aplicación, no del módulo que lo estrena.
--
-- Aplicar a las TRES bases de trabajo: `db` (sandbox), `db_prod_snapshot`
-- (golden) y `db_test`. En producción, a mano por phpMyAdmin.
--
-- endpoint VARCHAR(500): los endpoints de FCM y compañía pasan de largo de 255.
-- El índice único sobre 500 caracteres utf8mb4 ocupa 2000 bytes, por debajo del
-- límite de 3072 de InnoDB con formato de fila DYNAMIC, así que cabe.
--
-- La clave ajena apunta a `fos_user`: la tabla conserva el nombre histórico
-- desde que se retiró FOSUserBundle en la sub-fase 8.1.

CREATE TABLE push_subscription (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_push_subscription_user (user_id),
    UNIQUE INDEX uniq_push_subscription_endpoint (endpoint),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES fos_user (id) ON DELETE CASCADE;
