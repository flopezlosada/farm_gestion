-- ============================================================================
-- MIGRACIÓN DE ESQUEMA A PRODUCCIÓN
--
-- Calculada el 2026-08-31 comparando el dump de producción del 29-ago
-- (`gestioncsa (3).sql`, 107 tablas) contra el esquema que espera `main`
-- (127 tablas). Diferencia medida: 20 tablas y 3 columnas.
--
-- Validada ejecutándola sobre una copia real del dump: 107 tablas -> 127, sin
-- un solo error, y el resultado coincide columna a columna con el esquema que
-- espera el código. Así apareció volunteer_offer.coordinator_id, que llevaba
-- aplicada a mano en local y no estaba versionada en ningún sitio.
--
-- FICHERO DERIVADO, no fuente de verdad: es la concatenación en orden de
-- dependencias de los ficheros de `dev-docs/schema/`, que son los que se
-- revisan y se mantienen. Si algo se corrige, se corrige allí y este se vuelve
-- a componer.
--
-- ⚠️ SE EJECUTA ANTES DE SUBIR EL CÓDIGO. Al menos dos de estos bloques rompen
-- la aplicación ENTERA si el código llega primero: la campanita de avisos se
-- lee en los dos layouts, y el listado de reparto lee su relación al arrancar.
--
-- ⚠️ En phpMyAdmin, comprobar a mano que la base seleccionada es la de
-- producción: `DATABASE()` no es de fiar allí.
--
-- Lo que NO lleva este fichero, porque no hace falta:
--   · los ajustes nuevos de /gestion/settings — sin fila en `setting` caen a su
--     valor por defecto, y todos nacen apagados;
--   · `cron_run` y `emitted_effect` — ya están en producción desde el
--     planificador de tareas.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;


-- ----------------------------------------------------------------------------
-- Módulo de voluntariado: 8 tablas + partner.volunteering_opt_out
-- origen: dev-docs/schema/volunteering.sql
-- ----------------------------------------------------------------------------
-- Módulo de voluntariado — DDL de las tablas nuevas.
--
-- Generado con `doctrine:schema:update --dump-sql` y filtrado: NUNCA se aplica
-- con `--force`, que arrastraría el drift preexistente del resto del esquema
-- (entre otras cosas borraría índices que sólo existen a mano).
--
-- Aplicar a las TRES bases de trabajo: `db` (sandbox), `db_prod_snapshot`
-- (golden) y `db_test`. En producción, a mano por phpMyAdmin.
--
-- El orden importa: primero las tablas, después las claves ajenas.

CREATE TABLE volunteer_category (
    id INT AUTO_INCREMENT NOT NULL,
    name VARCHAR(80) NOT NULL,
    description LONGTEXT DEFAULT NULL,
    active TINYINT(1) DEFAULT 1 NOT NULL,
    UNIQUE INDEX uniq_volunteer_category_name (name),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE volunteer_offer (
    id INT AUTO_INCREMENT NOT NULL,
    node_id INT DEFAULT NULL,
    created_by_id INT DEFAULT NULL,
    -- De qué tarea salió ésta, si se creó repitiendo otra. SET NULL al borrar el
    -- original: perder la referencia es aceptable, perder las copias no.
    copied_from_id INT DEFAULT NULL,
    title VARCHAR(160) NOT NULL,
    description LONGTEXT DEFAULT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME DEFAULT NULL,
    remote TINYINT(1) DEFAULT 0 NOT NULL,
    place VARCHAR(160) DEFAULT NULL,
    slots INT DEFAULT NULL,
    companions_allowed TINYINT(1) DEFAULT 0 NOT NULL,
    credited_minutes INT DEFAULT NULL,
    open_to_anyone TINYINT(1) DEFAULT 0 NOT NULL,
    status VARCHAR(16) DEFAULT 'draft' NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX IDX_1BB85ACC460D9FD7 (node_id),
    INDEX IDX_1BB85ACCB03A8386 (created_by_id),
    INDEX IDX_1BB85ACC58B20D94 (copied_from_id),
    INDEX idx_volunteer_offer_starts_at (starts_at),
    INDEX idx_volunteer_offer_status (status),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE volunteer_offer_category (
    volunteer_offer_id INT NOT NULL,
    volunteer_category_id INT NOT NULL,
    INDEX IDX_C812B06C7B1A246F (volunteer_offer_id),
    INDEX IDX_C812B06CC76F2FD6 (volunteer_category_id),
    PRIMARY KEY(volunteer_offer_id, volunteer_category_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- Las preferencias del socix: qué categorías quiere que le avisen. Marcar
-- categorías significa "avísame de esto"; no marcar ninguna, "avísame de lo que
-- sea sencillo". De esa lectura depende el escalado de los avisos.
CREATE TABLE partner_volunteer_category (
    partner_id INT NOT NULL,
    volunteer_category_id INT NOT NULL,
    INDEX IDX_AE593AA49393F8FE (partner_id),
    INDEX IDX_AE593AA4C76F2FD6 (volunteer_category_id),
    PRIMARY KEY(partner_id, volunteer_category_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- uniq_volunteer_signup: impide que el doble submit apunte a alguien dos veces
-- y le haga ocupar dos plazas. En la BBDD y no por convención del código, como
-- component_key en partner_delivery_shift.
CREATE TABLE volunteer_signup (
    id INT AUTO_INCREMENT NOT NULL,
    offer_id INT NOT NULL,
    partner_id INT NOT NULL,
    companions INT DEFAULT 0 NOT NULL,
    -- En calidad de qué: 'participant' (fue a trabajar) o 'coordinator' (lo
    -- organizó). Coordinar computa horas igual, y tiene que hacerlo: quien monta
    -- el reparto todos los viernes no se apunta a las tareas, así que sin esto
    -- la gente que más sostiene el voluntariado salía con cero. Quien coordina
    -- NO ocupa plaza.
    role VARCHAR(16) DEFAULT 'participant' NOT NULL,
    notes LONGTEXT DEFAULT NULL,
    attended TINYINT(1) DEFAULT NULL,
    -- Quién dijo si fue o no: 'self' (la propia persona desde su panel, la vía
    -- normal) o 'manager' (gestión, cerrando o corrigiendo). Va siempre junto a
    -- `attended`: los dos nulos o los dos con valor.
    attendance_source VARCHAR(16) DEFAULT NULL,
    credited_minutes INT DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX IDX_1D22E30253C674EE (offer_id),
    INDEX idx_volunteer_signup_partner (partner_id),
    UNIQUE INDEX uniq_volunteer_signup (offer_id, partner_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- uniq_volunteer_call_scope: cada alcance se envía UNA vez por oferta. Es lo
-- que impide que el reintento del planificador —que reintenta al siguiente tick
-- cuando algo falla, por diseño— mande el mismo aviso dos veces.
CREATE TABLE volunteer_call (
    id INT AUTO_INCREMENT NOT NULL,
    offer_id INT NOT NULL,
    triggered_by_id INT DEFAULT NULL,
    scope VARCHAR(16) NOT NULL,
    recipients INT DEFAULT 0 NOT NULL,
    sent_at DATETIME NOT NULL,
    INDEX IDX_7A3F3E1253C674EE (offer_id),
    INDEX IDX_7A3F3E1263C5923F (triggered_by_id),
    UNIQUE INDEX uniq_volunteer_call_scope (offer_id, scope),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- Quién coordina cada área. La coordinación es un DATO y no un rol: un rol por
-- área obligaría a tocar security.yaml y desplegar cada vez que la asociación
-- abre un área nueva o cambia quién la lleva. De esta tabla se DERIVA
-- ROLE_GESTION_VOLUNTARIADO en User::getRoles().
CREATE TABLE volunteer_category_coordinator (
    volunteer_category_id INT NOT NULL,
    user_id INT NOT NULL,
    INDEX IDX_5DBDFDD6C76F2FD6 (volunteer_category_id),
    INDEX IDX_5DBDFDD6A76ED395 (user_id),
    PRIMARY KEY(volunteer_category_id, user_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE volunteer_category_coordinator ADD CONSTRAINT FK_5DBDFDD6C76F2FD6 FOREIGN KEY (volunteer_category_id) REFERENCES volunteer_category (id) ON DELETE CASCADE;
ALTER TABLE volunteer_category_coordinator ADD CONSTRAINT FK_5DBDFDD6A76ED395 FOREIGN KEY (user_id) REFERENCES fos_user (id) ON DELETE CASCADE;

-- El rastro de actividad del módulo: qué pasó, cuándo, sobre qué y quién lo
-- hizo. Mismo patrón que partner_event (type + payload json + actor con la
-- convención "gestor:{id}" / "partner:{id}" / "system" / "cli").
--
-- `category_id` existe ADEMÁS de `offer_id` porque hay eventos sin tarea
-- —crear un tipo de trabajo, cambiar quién lo coordina— y quien coordina un
-- área tiene que poder ver su actividad sin mezcla.
--
-- Todas las claves ajenas con SET NULL: el rastro de que algo ocurrió tiene que
-- sobrevivir a que la tarea, el área o la ficha desaparezcan.
CREATE TABLE volunteer_event (
    id INT AUTO_INCREMENT NOT NULL,
    offer_id INT DEFAULT NULL,
    category_id INT DEFAULT NULL,
    partner_id INT DEFAULT NULL,
    type VARCHAR(40) NOT NULL,
    actor VARCHAR(80) DEFAULT NULL,
    payload JSON DEFAULT NULL,
    occurred_at DATETIME NOT NULL,
    INDEX IDX_9C0D7559393F8FE (partner_id),
    INDEX idx_volunteer_event_occurred (occurred_at),
    INDEX idx_volunteer_event_offer (offer_id),
    INDEX idx_volunteer_event_category (category_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE volunteer_event ADD CONSTRAINT FK_9C0D75553C674EE FOREIGN KEY (offer_id) REFERENCES volunteer_offer (id) ON DELETE SET NULL;
ALTER TABLE volunteer_event ADD CONSTRAINT FK_9C0D75512469DE2 FOREIGN KEY (category_id) REFERENCES volunteer_category (id) ON DELETE SET NULL;
ALTER TABLE volunteer_event ADD CONSTRAINT FK_9C0D7559393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE SET NULL;

-- "De voluntariado no me avises, nunca." Salida explícita, distinta de no
-- marcar ninguna categoría (que significa "avísame de lo que sea sencillo").
-- Se respeta en las TRES consultas de audiencia, incluido el aviso general que
-- se lanza a mano: un "no" que el gestor se puede saltar no es un no.
ALTER TABLE partner ADD volunteering_opt_out TINYINT(1) DEFAULT 0 NOT NULL;

ALTER TABLE volunteer_offer ADD CONSTRAINT FK_1BB85ACC460D9FD7 FOREIGN KEY (node_id) REFERENCES node (id) ON DELETE SET NULL;
ALTER TABLE volunteer_offer ADD CONSTRAINT FK_1BB85ACC58B20D94 FOREIGN KEY (copied_from_id) REFERENCES volunteer_offer (id) ON DELETE SET NULL;
ALTER TABLE volunteer_offer ADD CONSTRAINT FK_1BB85ACCB03A8386 FOREIGN KEY (created_by_id) REFERENCES fos_user (id) ON DELETE SET NULL;
ALTER TABLE volunteer_offer_category ADD CONSTRAINT FK_C812B06C7B1A246F FOREIGN KEY (volunteer_offer_id) REFERENCES volunteer_offer (id) ON DELETE CASCADE;
ALTER TABLE volunteer_offer_category ADD CONSTRAINT FK_C812B06CC76F2FD6 FOREIGN KEY (volunteer_category_id) REFERENCES volunteer_category (id) ON DELETE CASCADE;
ALTER TABLE partner_volunteer_category ADD CONSTRAINT FK_AE593AA49393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE;
ALTER TABLE partner_volunteer_category ADD CONSTRAINT FK_AE593AA4C76F2FD6 FOREIGN KEY (volunteer_category_id) REFERENCES volunteer_category (id) ON DELETE CASCADE;
ALTER TABLE volunteer_signup ADD CONSTRAINT FK_1D22E30253C674EE FOREIGN KEY (offer_id) REFERENCES volunteer_offer (id) ON DELETE CASCADE;
ALTER TABLE volunteer_signup ADD CONSTRAINT FK_1D22E3029393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE;
ALTER TABLE volunteer_call ADD CONSTRAINT FK_7A3F3E1253C674EE FOREIGN KEY (offer_id) REFERENCES volunteer_offer (id) ON DELETE CASCADE;
ALTER TABLE volunteer_call ADD CONSTRAINT FK_7A3F3E1263C5923F FOREIGN KEY (triggered_by_id) REFERENCES fos_user (id) ON DELETE SET NULL;

-- ----------------------------------------------------------------------------
-- Voluntariado: tarea destacada
-- origen: dev-docs/schema/volunteer-featured.sql
-- ----------------------------------------------------------------------------
-- ============================================================================
-- Tareas destacadas — columna volunteer_offer.featured.
--
-- Sube una tarea a lo alto del panel de cada socix, por delante del orden normal
-- (punto de recogida propio y después fecha). Es el control editorial sobre la
-- portada: hay semanas en las que una cosa importa más que el orden automático.
--
-- DESTACAR NO ES FILTRAR: sin ninguna marcada, la portada sigue enseñando lo de
-- siempre. Por eso arrancar con la columna entera a 0 es el estado correcto y no
-- hay nada que sembrar.
--
-- El código (VolunteerOffer) MAPEA esta columna: hay que añadirla a la BBDD
-- ANTES de desplegar el código, o Doctrine la espera y revienta cualquier
-- pantalla que toque voluntariado.
--
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod.
-- Sin datos personales; idempotencia no nativa: correr una sola vez por entorno.
-- ============================================================================

ALTER TABLE volunteer_offer ADD featured TINYINT(1) DEFAULT 0 NOT NULL;

-- ----------------------------------------------------------------------------
-- Voluntariado: gente de fuera que echa una mano
-- origen: dev-docs/schema/volunteer-guests.sql
-- ----------------------------------------------------------------------------
-- Gente de fuera en una tarea de voluntariado.
--
-- Personas que vienen a echar una mano sin ser socixs y sin acompañar a nadie:
-- un grupo de estudiantes, gente de otra asociación, quien pasaba por allí. No
-- caben en `volunteer_signup.companions` porque aquello cuelga SIEMPRE de la
-- inscripción de un socix, y esta gente no tiene de quién colgar.
--
-- Un número y una nota, no filas: no son personas del sistema —no tienen ficha,
-- ni cuenta, ni horas— y darles una les inventaría una identidad que no existe.
-- Lo único que hace falta saber es cuántos brazos son, para que la tarea deje
-- de pedir gente que ya está cubierta, y quiénes eran, para que dentro de tres
-- meses se entienda por qué esa tarea salió adelante con dos socixs.
--
-- NO COMPUTAN HORAS a nadie: las horas son de socixs con ficha.
--
-- Aplicar en las tres bases de trabajo: db, db_prod_snapshot y db_test.

ALTER TABLE volunteer_offer
    ADD guests INT DEFAULT 0 NOT NULL,
    ADD guests_note VARCHAR(160) DEFAULT NULL;

-- ----------------------------------------------------------------------------
-- Voluntariado: preparación del reparto
-- origen: dev-docs/schema/volunteer-delivery-prep.sql
-- ----------------------------------------------------------------------------
-- ============================================================================
-- Montaje del reparto — columna volunteer_category.delivery_prep.
--
-- Marca cuál de las áreas de voluntariado es la de montar las cestas de un
-- punto de recogida. De ella sale el bloque "quién te prepara la cesta" del
-- panel del socix, y el aviso de que no se ha apuntado nadie.
--
-- El código (VolunteerCategory) MAPEA esta columna: hay que añadirla a la BBDD
-- ANTES de desplegar el código, o Doctrine la espera y revienta cualquier
-- pantalla que toque voluntariado.
--
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod.
-- Sin datos personales; idempotencia no nativa: correr una sola vez por entorno.
--
-- Después, EN LA WEB (Voluntariado › Áreas), marcar "Es el montaje del reparto"
-- en el área que corresponda. Sin marcarla, nada cambia: la columna a 0 deja el
-- bloque apagado en todos los puntos, que es el estado de partida correcto.
-- ============================================================================

ALTER TABLE volunteer_category ADD delivery_prep TINYINT(1) DEFAULT 0 NOT NULL;

-- ----------------------------------------------------------------------------
-- Voluntariado: quién coordina cada tarea
-- origen: dev-docs/schema/volunteer-offer-coordinator.sql
-- ----------------------------------------------------------------------------
-- Quién coordina una tarea de voluntariado concreta.
--
-- Apunta a `partner` y no a `fos_user`: quien coordina una tarea suelta puede
-- ser cualquier socix, no hace falta que tenga cuenta. Es distinto de
-- `volunteer_category_coordinator`, que sí cuelga de fos_user porque de aquello
-- se derivan permisos de gestión.
--
-- ON DELETE SET NULL: si se borra la ficha de quien coordinaba, la tarea se
-- queda sin coordinación pero NO se borra. Perder el dato es recuperable;
-- perder la tarea, no.
--
-- ⚠️ ESTE FICHERO SE ESCRIBIÓ TARDE. La columna llevaba aplicada a mano en las
-- bases locales desde el commit que la introdujo, pero sin DDL versionado, así
-- que un despliegue a producción habría subido el código sin ella. Se descubrió
-- el 2026-08-31 comparando un dump de producción contra el esquema que espera
-- `main`. Ver el aviso de [[schema_drift_anotaciones_vs_db]]: el DDL a mano que
-- no se versiona es invisible hasta que rompe.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.

ALTER TABLE volunteer_offer ADD coordinator_id INT DEFAULT NULL;

CREATE INDEX idx_volunteer_offer_coordinator ON volunteer_offer (coordinator_id);

ALTER TABLE volunteer_offer
    ADD CONSTRAINT fk_volunteer_offer_coordinator FOREIGN KEY (coordinator_id) REFERENCES partner (id) ON DELETE SET NULL;

-- ----------------------------------------------------------------------------
-- Voluntariado: rastro de actividad
-- origen: dev-docs/schema/volunteer-coordination-log.sql
-- ----------------------------------------------------------------------------
-- Horas de coordinar un ÁREA.
--
-- Coordinar un área es un trabajo continuo que no ocurre un día concreto:
-- buscar gente, cuadrarla, avisar, estar pendiente. No cabe en una tarea —una
-- VolunteerOffer tiene fecha, plazas y gente que se apunta— y tampoco en la
-- coordinación de una tarea suelta, que dice QUIÉN la monta pero no cuánto
-- tiempo se va en llevar el área entera.
--
-- El parte es LIBRE: quien coordina apunta horas cuando quiere, diciendo de qué
-- área, qué día y cuántas. Coordinar no ocurre un día concreto, así que no hay
-- fecha de la que deducirlo ni tarea que cerrar.
--
-- Va contra `partner` y no contra `fos_user` —aunque la coordinación del área
-- cuelgue de la cuenta— porque las horas son de un socix con ficha, que es
-- quien las tiene en su contador.
--
-- Aplicar en las tres bases de trabajo: db, db_prod_snapshot y db_test.

CREATE TABLE volunteer_coordination_log (
    id INT AUTO_INCREMENT NOT NULL,
    partner_id INT NOT NULL,
    category_id INT NOT NULL,
    happened_on DATE NOT NULL COMMENT 'Día al que se imputan las horas',
    minutes INT NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_vcl_partner (partner_id),
    INDEX idx_vcl_category (category_id),
    INDEX idx_vcl_happened (happened_on),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE volunteer_coordination_log
    ADD CONSTRAINT fk_vcl_partner FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE;

ALTER TABLE volunteer_coordination_log
    ADD CONSTRAINT fk_vcl_category FOREIGN KEY (category_id) REFERENCES volunteer_category (id) ON DELETE CASCADE;

-- ----------------------------------------------------------------------------
-- Avisos al móvil: suscripciones del navegador
-- origen: dev-docs/schema/push-subscription.sql
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- Bandeja de avisos in-app (la campanita)
-- origen: dev-docs/schema/notification.sql
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- Preferencias de avisos por socix y canal
-- origen: dev-docs/schema/notification-opt-out.sql
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- Grupo de consumo: 7 tablas + fos_user.producer_id
-- origen: dev-docs/schema/consumer-group.sql
-- ----------------------------------------------------------------------------
-- Grupo de consumo: productoras, catálogo, rondas y pedidos.
--
-- El DDL que le faltaba a la PR #95: el módulo entró en main con sus entidades
-- pero sin el SQL, así que en cualquier base ya existente ni el login arranca
-- —`fos_user` gana una relación con Producer y Doctrine la pide al cargar la
-- cuenta—.
--
-- Sale de `doctrine:schema:update --dump-sql`, FILTRADO a mano: el dump pide
-- además renombrar índices y tocar `component_key`, y eso último es el drift
-- que impide reimportar el dump de producción. Nada de --force.
--
-- Aplicar en las tres bases de trabajo: db, db_prod_snapshot y db_test.

CREATE TABLE consumer_group_round (id INT AUTO_INCREMENT NOT NULL, producer_id INT NOT NULL, created_by_id INT DEFAULT NULL, title VARCHAR(180) NOT NULL, status SMALLINT NOT NULL, confirmed TINYINT(1) NOT NULL, minimum_condition VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, provider_note LONGTEXT DEFAULT NULL, cancel_reason LONGTEXT DEFAULT NULL, closed_at DATETIME DEFAULT NULL, confirmed_at DATETIME DEFAULT NULL, delivered_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, orders_close_at DATETIME NOT NULL, delivery_date DATE NOT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, INDEX IDX_17D0A12F89B658FE (producer_id), INDEX IDX_17D0A12FB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE consumer_group_order_line (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, round_item_id INT NOT NULL, quantity NUMERIC(8, 2) NOT NULL, INDEX IDX_5A3B98498D9F6D38 (order_id), INDEX IDX_5A3B9849CDCB0AA4 (round_item_id), UNIQUE INDEX uniq_cg_order_line_order_item (order_id, round_item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE consumer_group_round_item (id INT AUTO_INCREMENT NOT NULL, round_id INT NOT NULL, product_id INT NOT NULL, price NUMERIC(8, 2) NOT NULL, sort_order SMALLINT NOT NULL, INDEX IDX_7C722216A6005CA0 (round_id), INDEX IDX_7C7222164584665A (product_id), UNIQUE INDEX uniq_cg_round_item_round_product (round_id, product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE consumer_group_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, sort_order SMALLINT NOT NULL, active TINYINT(1) NOT NULL, UNIQUE INDEX UNIQ_C4D3DEE75E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE consumer_group_order (id INT AUTO_INCREMENT NOT NULL, round_id INT NOT NULL, partner_id INT NOT NULL, paid TINYINT(1) NOT NULL, paid_at DATETIME DEFAULT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, INDEX IDX_2717D883A6005CA0 (round_id), INDEX IDX_2717D8839393F8FE (partner_id), UNIQUE INDEX uniq_cg_order_round_partner (round_id, partner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE consumer_group_producer (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, contact_name VARCHAR(180) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, web VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, minimum_note VARCHAR(255) DEFAULT NULL, self_managed TINYINT(1) NOT NULL, active TINYINT(1) NOT NULL, created DATETIME NOT NULL, updated DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE consumer_group_product (id INT AUTO_INCREMENT NOT NULL, producer_id INT NOT NULL, category_id INT DEFAULT NULL, name VARCHAR(180) NOT NULL, image VARCHAR(255) DEFAULT NULL, unit VARCHAR(30) NOT NULL, description LONGTEXT DEFAULT NULL, reference_price NUMERIC(8, 2) DEFAULT NULL, active TINYINT(1) NOT NULL, sort_order SMALLINT NOT NULL, INDEX IDX_9B72851189B658FE (producer_id), INDEX IDX_9B72851112469DE2 (category_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE consumer_group_round ADD CONSTRAINT FK_17D0A12F89B658FE FOREIGN KEY (producer_id) REFERENCES consumer_group_producer (id) ON DELETE RESTRICT;
ALTER TABLE consumer_group_round ADD CONSTRAINT FK_17D0A12FB03A8386 FOREIGN KEY (created_by_id) REFERENCES fos_user (id) ON DELETE SET NULL;
ALTER TABLE consumer_group_order_line ADD CONSTRAINT FK_5A3B98498D9F6D38 FOREIGN KEY (order_id) REFERENCES consumer_group_order (id) ON DELETE CASCADE;
ALTER TABLE consumer_group_order_line ADD CONSTRAINT FK_5A3B9849CDCB0AA4 FOREIGN KEY (round_item_id) REFERENCES consumer_group_round_item (id) ON DELETE CASCADE;
ALTER TABLE consumer_group_round_item ADD CONSTRAINT FK_7C722216A6005CA0 FOREIGN KEY (round_id) REFERENCES consumer_group_round (id) ON DELETE CASCADE;
ALTER TABLE consumer_group_round_item ADD CONSTRAINT FK_7C7222164584665A FOREIGN KEY (product_id) REFERENCES consumer_group_product (id) ON DELETE RESTRICT;
ALTER TABLE consumer_group_order ADD CONSTRAINT FK_2717D883A6005CA0 FOREIGN KEY (round_id) REFERENCES consumer_group_round (id) ON DELETE CASCADE;
ALTER TABLE consumer_group_order ADD CONSTRAINT FK_2717D8839393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE;
ALTER TABLE consumer_group_product ADD CONSTRAINT FK_9B72851189B658FE FOREIGN KEY (producer_id) REFERENCES consumer_group_producer (id) ON DELETE CASCADE;
ALTER TABLE consumer_group_product ADD CONSTRAINT FK_9B72851112469DE2 FOREIGN KEY (category_id) REFERENCES consumer_group_category (id) ON DELETE SET NULL;
ALTER TABLE fos_user ADD producer_id INT DEFAULT NULL;
ALTER TABLE fos_user ADD CONSTRAINT FK_957A647989B658FE FOREIGN KEY (producer_id) REFERENCES consumer_group_producer (id) ON DELETE SET NULL;
CREATE UNIQUE INDEX UNIQ_957A647989B658FE ON fos_user (producer_id);

-- ----------------------------------------------------------------------------
-- Listado de reparto por correo: quién lo recibe en cada punto
-- origen: dev-docs/schema/node-sheet-recipient.sql
-- ----------------------------------------------------------------------------
-- Quién recibe por correo el listado de reparto de cada nodo cuando se cierra
-- su plazo de cambios (app:send-delivery-sheets).
--
-- NO es "quién coordina el nodo", y la distinción importa: el listado también lo
-- necesita quien monta el reparto ese día, que no es la persona que coordina. Si
-- esto fuera la coordinación, para que le llegara el correo habría que nombrarla
-- coordinadora —falso— y en este proyecto de la coordinación se DERIVAN permisos
-- (volunteer_category_coordinator concede ROLE_GESTION_VOLUNTARIADO). De esta
-- tabla no se deriva ningún rol: sólo dice a dónde va un adjunto.
--
-- APUNTA A `partner`, NO A `fos_user`. Se midió: 402 socixs con correo frente a
-- 43 cuentas, de las que 12 tienen permisos de gestión. Contra `fos_user`, quien
-- monta el reparto —que casi nunca tiene cuenta— era inseleccionable y esto sólo
-- servía para doce personas. Recibir un correo no exige poder entrar en la web.
--
-- Nodo sin filas aquí = su listado cae al ajuste general
-- `email.delivery_sheet_to` de la tabla `setting`, que sigue de respaldo.
--
-- ORDEN DE DESPLIEGUE: esta tabla ANTES que el código. El comando lee la
-- relación en cuanto arranca y, sin tabla, la tarea reventaría en el primer tick
-- de la mañana.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.
--
-- Los nombres de índice son los que GENERA Doctrine, no unos legibles. Con
-- nombres propios, `doctrine:schema:update --dump-sql` propone renombrarlos en
-- cada ejecución, para siempre, y ese ruido permanente tapa los cambios de
-- esquema de verdad — que es justo la herramienta con la que aquí se caza el
-- drift.

CREATE TABLE node_sheet_recipient (
    node_id INT NOT NULL,
    partner_id INT NOT NULL,
    INDEX IDX_BFC06743460D9FD7 (node_id),
    INDEX IDX_BFC067439393F8FE (partner_id),
    PRIMARY KEY(node_id, partner_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- ON DELETE CASCADE en los dos lados: si se borra un nodo o una ficha de socix,
-- la fila deja de tener sentido. Perder un destinatario es inocuo (el listado cae
-- al ajuste general); dejar una fila huérfana rompería la carga de la relación.
ALTER TABLE node_sheet_recipient
    ADD CONSTRAINT FK_node_sheet_recipient_node FOREIGN KEY (node_id) REFERENCES node (id) ON DELETE CASCADE;
ALTER TABLE node_sheet_recipient
    ADD CONSTRAINT FK_node_sheet_recipient_partner FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE;
