-- ============================================================================
-- Bandeja de avisos in-app (la campanita) — tabla notification.
--
-- Guarda una fila por aviso y destinatario: es la copia que NO se pierde. El
-- correo y el push son empujones que llegan al sitio donde estás; esto es lo que
-- queda cuando alguien los apaga, y es justo lo que hace que apagarlos se pueda
-- permitir. Por eso NO tiene interruptor en la pantalla de preferencias y no
-- aparece en `notification_opt_out`.
--
-- EL DESTINATARIO ES UNA CUENTA (fos_user) Y NO UN SOCIX, aunque los avisos se
-- decidan por socix: esto se lee entrando en la web, y quien entra es una cuenta.
-- Además hay avisos que no son de socixs —"a esta gente le faltan datos" va a
-- quien coordina socixs, que puede ser una cuenta de gestión sin ficha de socix
-- detrás—. Los envíos resuelven socix → cuenta(s) con UserRepository::findByPartners().
--
-- NO HAY COLUMNA CON EL DESTINO. La pantalla que abre cada aviso se deduce del
-- `kind` en un único sitio del código (App\Service\Notification\NotificationLink),
-- el mismo que usa el payload del push, para que la fila de la bandeja y el aviso
-- del móvil no puedan llevar a pantallas distintas. Una columna por módulo sería
-- una columna nueva por cada aviso que se añada.
--
-- ÍNDICE ÚNICO Y COMPUESTO (recipient_id, read_at, created_at): sirve para las
-- dos consultas que existen —el número de la campanita, que filtra por cuenta y
-- read_at IS NULL, y el listado, que filtra por cuenta y ordena por fecha—. Dos
-- índices separados no darían nada más y los pagaría cada inserción de la tanda
-- diaria del planificador.
--
-- SIN PURGA a propósito: son unos pocos avisos por socix y semana (del orden de
-- diez mil filas al año para los 246), y el histórico de qué se avisó a quién es
-- lo que se quiere poder mirar cuando alguien dice que no le llegó nada.
--
-- Aplicar a las TRES bases de trabajo (db, db_prod_snapshot, db_test) y en
-- producción ANTES de subir el código: la campanita del panel la lee en cada
-- página.
-- ============================================================================

CREATE TABLE IF NOT EXISTS notification (
    id INT AUTO_INCREMENT NOT NULL,
    recipient_id INT NOT NULL,
    kind VARCHAR(40) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    read_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    PRIMARY KEY (id),
    KEY idx_notification_recipient (recipient_id, read_at, created_at),
    CONSTRAINT fk_notification_recipient
        FOREIGN KEY (recipient_id) REFERENCES fos_user (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
