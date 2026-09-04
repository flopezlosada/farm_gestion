-- ============================================================================
-- MIGRACIÓN DE ESQUEMA A STAGING · 4 de septiembre de 2026
--
-- Lleva una base que esté en el esquema de PRODUCCIÓN (el dump del 29 de agosto,
-- 107 tablas) hasta el esquema que espera `main` hoy. Sirve igual para producción
-- cuando toque: lo que cambia es sobre qué base se ejecuta.
--
-- FICHERO DERIVADO, no fuente de verdad: es la concatenación en orden de
-- dependencias de los ficheros de `dev-docs/schema/`, que son los que se revisan
-- y se mantienen. Si algo se corrige, se corrige allí y este se vuelve a componer.
--
-- Está partido en tres partes y NO todas se ejecutan siempre:
--
--   PARTE A — lo del 31 de agosto (voluntariado, avisos, grupo de consumo,
--             destinatarios del listado). Es `migracion-prod-2026-08-31.sql` tal
--             cual. SÁLTATELA si esa migración ya se aplicó a esta base.
--   PARTE B — lo posterior: turnos de voluntariado, montaje de cestas, tareas de
--             rutina y el traslado que acumula. Se ejecuta SIEMPRE.
--   PARTE C — una sola columna que se retira. Va DESPUÉS de subir el código.
--
-- ⚠️ LAS PARTES A Y B SE EJECUTAN ANTES DE SUBIR EL CÓDIGO. Varios de estos
-- bloques rompen la aplicación ENTERA si el código llega primero: la campanita de
-- avisos se lee en los dos layouts, y voluntariado deja de abrir en cuanto
-- Doctrine espera una columna que no está.
--
-- ⚠️ LA PARTE C VA DESPUÉS, y por el motivo contrario: mientras el código viejo
-- siga arriba, esa columna tiene que existir. Una columna de más que el código ya
-- no mapea es inofensiva; una de menos que todavía mapea son 500 en todo el
-- módulo. Pasó el 4 de septiembre en local, en ese orden exacto.
--
-- ⚠️ NO ES IDEMPOTENTE. Se ejecuta una sola vez por entorno. Repetirla falla en la
-- primera clave ajena duplicada y deja el resto sin aplicar.
--
-- ⚠️ En phpMyAdmin, comprobar a mano que la base seleccionada es la que toca:
-- `DATABASE()` no es de fiar allí.
--
-- CÓMO SABER SI HACE FALTA LA PARTE A. Esta consulta contesta que sí cuando
-- devuelve algo menos que 4 en la columna `tablas_de_la_parte_a`:
--
--   SELECT COUNT(*) AS tablas_de_la_parte_a
--   FROM information_schema.tables
--   WHERE table_schema = DATABASE()
--     AND table_name IN ('volunteer_offer', 'notification',
--                        'consumer_group_round', 'node_sheet_recipient');
--
-- VALIDACIÓN (2026-09-04). Ejecutada sobre una copia real de la base local
-- anterior a todo esto: el resultado coincide columna a columna con el esquema
-- que espera `main`. `doctrine:schema:update --dump-sql` sobre la base resultante
-- sólo propone el drift viejo y conocido —`component_key` calculada como VIRTUAL
-- en vez de STORED, unos cuantos tipos de columna y varios renombrados de índice
-- de antes de agosto—, exactamente el mismo que propone sobre la base golden.
--
-- Lo que NO lleva este fichero, porque no hace falta:
--   · los ajustes de /gestion/settings — sin fila en `setting` caen a su valor por
--     defecto, y todos nacen apagados;
--   · `cron_run` y `emitted_effect` — ya están en producción desde el planificador.
-- ============================================================================

-- ############################################################################
-- PARTE A · Migración del 31 de agosto
--
-- SÁLTALA si esta base ya la tiene aplicada (mira la consulta de la cabecera).
-- Va tal cual está en dev-docs/schema/migracion-prod-2026-08-31.sql.
-- ############################################################################
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


-- ############################################################################
-- PARTE B · Lo posterior al 31 de agosto
--
-- Se ejecuta SIEMPRE, y ANTES de subir el código.
-- ############################################################################


-- --------------------------------------------------------------------------
-- Turnos de voluntariado: la tarea deja de llevar la fecha
-- origen: dev-docs/schema/volunteer-shifts.sql
-- --------------------------------------------------------------------------
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
--
-- LOS NOMBRES DE LOS ÍNDICES DE CLAVE AJENA SON LOS QUE GENERA DOCTRINE
-- (`IDX_<hash>`), no unos legibles. Con nombres propios,
-- `doctrine:schema:update --dump-sql` propone renombrarlos en cada ejecución,
-- para siempre, y ese ruido permanente tapa los cambios de esquema de verdad
-- —que es justo la herramienta con la que aquí se caza el drift—. Es la misma
-- regla que sigue `migracion-prod-2026-08-31.sql`.
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
  INDEX `IDX_9765E6B753C674EE` (`offer_id`),
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
  ADD INDEX `IDX_1D22E302BB70BC0E` (`shift_id`);
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
  ADD INDEX `IDX_7A3F3E12BB70BC0E` (`shift_id`);
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
  ADD INDEX `IDX_1BB85ACCDA6A219` (`place_id`);
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

-- --------------------------------------------------------------------------
-- Montaje de cestas: lo que decide cada punto de recogida
-- origen: dev-docs/schema/node-delivery-prep.sql
-- --------------------------------------------------------------------------
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

-- --------------------------------------------------------------------------
-- Montaje de cestas: la convocatoria que sale del reparto
-- origen: dev-docs/schema/volunteer-offer-delivery-prep.sql
-- --------------------------------------------------------------------------
-- ============================================================================
-- El montaje de las cestas, en la convocatoria: dos columnas en
-- `volunteer_offer`.
--
-- `delivery_prep`      esta convocatoria es el montaje de las cestas de su
--                      punto de recogida. Sustituye a la casilla del tipo de
--                      trabajo (`volunteer_category.delivery_prep`), que
--                      señalaba una sola cosa en toda la asociación y permitía
--                      marcarla cero veces o dos. NADIE la marca a mano: la
--                      pone quien crea la convocatoria, que es el sistema.
--
-- `repeat_offset_days` días entre el trabajo y la fecha que dicta el calendario
--                      de reparto: 0 el mismo día, -1 la víspera. Existe porque
--                      las cestas se montan antes de repartirlas, a veces la
--                      tarde anterior, y la cadencia "los días que haya reparto"
--                      sólo sabía convocar el día físico de la entrega.
--
-- El índice acompaña a la consulta que busca la convocatoria de montaje de un
-- punto, que se hace en cada guardado del punto y en cada pasada del cron.
--
-- El código (App\Entity\VolunteerOffer) MAPEA estas columnas: hay que añadirlas
-- ANTES de desplegar el código, o Doctrine las espera y revienta todo
-- voluntariado.
--
-- Va DESPUÉS de `dev-docs/schema/volunteer-shift.sql` (la tabla ya tiene que
-- llevar sus columnas de receta) y ANTES de
-- `dev-docs/schema/volunteer-category-delivery-prep-drop.sql`.
--
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod.
-- Sin datos personales; idempotencia no nativa: correr una sola vez por entorno.
-- ============================================================================

ALTER TABLE volunteer_offer
    ADD delivery_prep TINYINT(1) DEFAULT 0 NOT NULL,
    ADD repeat_offset_days SMALLINT DEFAULT 0 NOT NULL;

CREATE INDEX idx_volunteer_offer_delivery_prep ON volunteer_offer (node_id, delivery_prep);

-- --------------------------------------------------------------------------
-- Tareas de rutina: sus plazas libres no son un aviso
-- origen: dev-docs/schema/volunteer-routine.sql
-- --------------------------------------------------------------------------
-- ============================================================================
-- Tareas de rutina — columna volunteer_offer.routine.
--
-- Una tarea de rutina (una plaza, poco rato, todos los días: sacar al perro) se
-- cubre sola y no dispara el aviso de plazas en el calendario. Con dos turnos
-- diarios, el aviso ocre saldría en cincuenta fichas al mes y dejaría de
-- significar nada justo donde hace falta. Las plazas libres se siguen viendo,
-- en gris.
--
-- Es una marca de la TAREA y la pone quien la crea desde el formulario. Arrancar
-- con todo a 0 es el estado correcto: ninguna tarea es de rutina hasta que
-- alguien lo dice.
--
-- El código (VolunteerOffer) MAPEA esta columna: hay que añadirla a la BBDD
-- ANTES de desplegar el código, o Doctrine la espera y revienta cualquier
-- pantalla que toque voluntariado.
--
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod.
-- Sin datos personales; idempotencia no nativa: correr una sola vez por entorno.
-- ============================================================================

ALTER TABLE volunteer_offer ADD routine TINYINT(1) DEFAULT 0 NOT NULL;

-- --------------------------------------------------------------------------
-- Traslado que acumula: a qué cesta se sumó la que no se recoge
-- origen: dev-docs/schema/partner-delivery-shift-accumulated-to.sql
-- --------------------------------------------------------------------------
-- ============================================================================
--  `partner_delivery_shift`: columna `accumulated_to_basket_id`.
--
--  🔴 ORDEN DE DESPLIEGUE: ESTE SQL **ANTES** DEL CÓDIGO.
--  La entidad mapea la columna nueva; sin ella, cualquier lectura de un
--  PartnerDeliveryShift revienta y eso es TODO el calendario de reparto (gestor
--  y socix) más el generador semanal. Con la columna puesta y el código viejo
--  todavía arriba no pasa nada: nadie la escribe y queda a NULL, que es
--  exactamente el comportamiento actual.
--
--  Aditivo y reversible. No reconstruye la tabla (ADD COLUMN nullable es
--  INSTANT en MySQL 8) y no toca ningún índice existente.
-- ============================================================================
--
-- QUÉ RESUELVE. "Trasladar sumando" lleva la cesta de una semana a un día en el
-- que el socix ya recoge: ese día pasa a llevar dos cestas. Como un día no puede
-- tener dos WeeklyBaskets del mismo socix, la segunda vive como cesta extra
-- (`partner_basket_extra`) y la semana de origen se vacía con un intent SIN
-- destino (`to_basket_id` NULL) — el mismo que usa "no recoge".
--
-- Y ahí estaba el problema: ese intent era INDISTINGUIBLE de un "no recoge", así
-- que el calendario pintaba la cesta en la papelera como pendiente mientras ya
-- estaba colocada (y sumada) en el otro día. La cesta se contaba DOS VECES, y la
-- tarjeta de la papelera invitaba a recuperarla → una cesta de más en el listado
-- impreso. `accumulated_to_basket_id` dice a qué semana se fue: con valor, la
-- cesta está colocada y no sale en la papelera; a NULL, es un "no recoge" de
-- verdad y sigue pendiente.
--
-- Es además el hilo para DESHACER el traslado en un gesto ("Deshacer el
-- traslado" en el día destino), que antes había que hacer en dos pasos
-- (quitar la extra + recuperar la del origen) y que hecho a medias dejaba una
-- cesta de más o de menos.


-- ============================================================================
-- PASO 1 — DIAGNÓSTICO. ¿Está ya la columna?
--
-- Sustituir 'NOMBRE_DE_LA_BASE' por el nombre literal: 'gestioncsa' (prod),
-- 'csastaging' (staging), 'db', 'db_prod_snapshot', 'db_test'.
--
-- ⚠️ NO usar `DATABASE()`: navegando information_schema en phpMyAdmin la base
-- "actual" es information_schema y la consulta devuelve cero filas SIN error.
--
-- ⚠️ Comprobar que la base es la que crees: en este hosting hay una llamada
-- `csavegadejarama` que NO es producción. Prueba rápida:
-- `SELECT COUNT(*) FROM <base>.weekly_basket;` debe dar miles de filas.
-- ============================================================================

SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'NOMBRE_DE_LA_BASE'
  AND TABLE_NAME = 'partner_delivery_shift'
  AND COLUMN_NAME = 'accumulated_to_basket_id';

-- Cero filas → aplicar el PASO 2. Una fila → ya está, saltar al PASO 3.


-- ============================================================================
-- PASO 2 — LA COLUMNA. Los tres statements, en este orden.
--
-- El nombre de la clave ajena NO es decorativo: es el que genera Doctrine
-- (`FK_B49754EC15F19D1E`, sacado de `doctrine:schema:update --dump-sql`). Con
-- otro nombre, cada dump-sql futuro propondría recrearla y el drift de esquema
-- vuelve a crecer — la lección de `node_sheet_recipient` (#111).
--
-- ON DELETE CASCADE igual que `from_basket_id` / `to_basket_id`: si se borra un
-- Basket, sus intents se van con él.
-- ============================================================================

ALTER TABLE partner_delivery_shift
  ADD accumulated_to_basket_id INT DEFAULT NULL;

ALTER TABLE partner_delivery_shift
  ADD CONSTRAINT FK_B49754EC15F19D1E FOREIGN KEY (accumulated_to_basket_id)
  REFERENCES basket (id) ON DELETE CASCADE;

CREATE INDEX idx_pds_accumulated_to ON partner_delivery_shift (accumulated_to_basket_id);


-- ============================================================================
-- PASO 3 — DATOS YA EXISTENTES. Los traslados sumando hechos ANTES de este
-- cambio quedan con la columna a NULL, o sea, contados como "no recoge": su
-- cesta sigue apareciendo en la papelera aunque esté colocada en otro día. Hay
-- que marcarlos, o el bug sigue vivo para ellos.
--
-- Cómo se reconocen: `AccumulatingMove` hace las dos escrituras en la misma
-- transacción, así que el intent sin destino y la cesta extra del destino
-- comparten `created` AL SEGUNDO para el mismo socix. Esa coincidencia es la
-- firma; un "no recoge" normal no la tiene.
--
-- Solo importan las semanas FUTURAS: un traslado cuya semana de origen ya pasó
-- no se puede recuperar de la papelera (el calendario es solo lectura ahí), así
-- que no puede producir la cesta de más.
-- ============================================================================

-- 3a. LISTAR los candidatos. Mirar el resultado antes de tocar nada.
SELECT s.id            AS shift_id,
       s.partner_id,
       s.from_basket_id,
       fb.date         AS semana_origen,
       e.basket_id     AS destino_basket_id,
       db.date         AS semana_destino,
       s.created
FROM partner_delivery_shift s
JOIN basket fb ON fb.id = s.from_basket_id
JOIN partner_basket_extra e
       ON e.partner_id = s.partner_id
      AND e.created = s.created
JOIN basket db ON db.id = e.basket_id
WHERE s.to_basket_id IS NULL
  AND s.component_id IS NULL
  AND s.accumulated_to_basket_id IS NULL
  AND fb.date >= CURDATE()
GROUP BY s.id, s.partner_id, s.from_basket_id, fb.date, e.basket_id, db.date, s.created
ORDER BY fb.date;

-- En el dump de producción del 2026-09-04 esto devuelve UNA fila:
--   shift 116 · from_basket 510 (2026-09-04) · destino 512 (2026-09-18)
-- Es un traslado real hecho desde gestión el 2026-09-03 a las 23:42:56.

-- 3b. MARCARLOS. Mismo criterio que la consulta de arriba, aplicado con UPDATE.
--     Descomentar y ejecutar SOLO después de mirar el listado de 3a.
--
-- UPDATE partner_delivery_shift s
-- JOIN basket fb ON fb.id = s.from_basket_id
-- JOIN (
--         SELECT e.partner_id, e.created, MIN(e.basket_id) AS basket_id
--         FROM partner_basket_extra e
--         GROUP BY e.partner_id, e.created
--         HAVING COUNT(DISTINCT e.basket_id) = 1
--      ) x ON x.partner_id = s.partner_id AND x.created = s.created
-- SET s.accumulated_to_basket_id = x.basket_id
-- WHERE s.to_basket_id IS NULL
--   AND s.component_id IS NULL
--   AND s.accumulated_to_basket_id IS NULL
--   AND fb.date >= CURDATE();
--
-- (El subselect exige que todas las líneas de extra de ese instante apunten a
-- la MISMA semana. Si no —dos añadidos distintos en el mismo segundo, que no se
-- ha visto nunca—, la fila se queda sin marcar y hay que resolverla a mano en
-- vez de adivinar el destino.)

-- 3c. Alternativa a mano si 3a devuelve poco (el caso de producción). Más
--     seguro que el UPDATE con JOIN: se ve exactamente qué se escribe.
--
-- UPDATE partner_delivery_shift SET accumulated_to_basket_id = 512 WHERE id = 116;


-- ============================================================================
-- VERIFICACIÓN FINAL
-- ============================================================================

-- La columna existe y la clave ajena tiene el nombre de Doctrine:
-- SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
-- FROM information_schema.KEY_COLUMN_USAGE
-- WHERE TABLE_SCHEMA = 'NOMBRE_DE_LA_BASE'
--   AND TABLE_NAME = 'partner_delivery_shift'
--   AND COLUMN_NAME = 'accumulated_to_basket_id';

-- Ya no queda ningún traslado futuro sin marcar (3a debe devolver cero filas).

-- Y en pantalla: el calendario del socix cuya cesta se trasladó debe mostrar el
-- día de origen VACÍO y la papelera SIN esa tarjeta, y el día destino con dos
-- cestas y el botón "Deshacer el traslado".

-- NOTA: `doctrine:schema:update --dump-sql` seguirá reemitiendo el CHANGE de
-- `component_key` y la FK de `component_id`. Es drift preexistente y cosmético
-- (ver partner-delivery-shift-component-key.sql). NUNCA correr
-- `schema:update --force` en este proyecto.


-- ############################################################################
-- PARTE C · DESPUÉS de subir el código
--
-- Retira una columna que el código nuevo ya no usa. Mientras el código viejo
-- siga arriba tiene que existir, así que esto va al final, con la web nueva ya
-- funcionando. Si se te olvida no pasa nada: es una columna de más.
-- ############################################################################
-- ============================================================================
-- Retirada de `volunteer_category.delivery_prep`.
--
-- La marca del montaje se mudó al punto de recogida
-- (`dev-docs/schema/node-delivery-prep.sql`), donde tener uno, ninguno o todos
-- marcados es válido. En el tipo de trabajo no lo era: servía para señalar una
-- sola cosa en toda la asociación, y aun así permitía cero —panel mudo— y dos
-- —panel señalando a quien friega el suelo—.
--
-- ⚠️⚠️ ESTE VA DESPUÉS DEL CÓDIGO, al revés que casi todos los de esta carpeta,
-- Y NO ES UNA SUGERENCIA: se ejecutó antes de tiempo el 2026-09-03 y dejó el
-- escaparate en 500 con
--
--     SQLSTATE[42S22]: Column not found: 1054
--     Unknown column 't0.delivery_prep' in 'field list'
--
-- en TODA pantalla que cargara un área de voluntariado. La regla de «el SQL
-- antes del código» vale para AÑADIR: una columna nueva que el código viejo
-- ignora no molesta a nadie. Con un DROP el orden se INVIERTE, porque una
-- columna que la entidad sigue mapeando y ya no existe tumba la aplicación.
--
-- Y OJO CON QUÉ CÓDIGO CORRE EN CADA BASE, que es lo que falló: en local, `db`
-- la sirve el árbol principal —hoy la rama `pruebas`—, no la rama donde se está
-- trabajando. Que la entidad esté limpia en tu rama no basta: tiene que estarlo
-- en la rama que sirve esa base.
--
-- Orden correcto, entorno por entorno: subir el código que ya no mapea la
-- columna, comprobar que las pantallas de voluntariado cargan, y entonces
-- ejecutar esto. En producción, entre las dos cosas hay un mirror por FTP: si se
-- invierte, voluntariado se queda caído todo ese rato.
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
-- Aplicar a las tres locales (db, db_prod_snapshot, db_test) y a prod, cada una
-- DESPUÉS de que su código deje de mapear la columna. A 2026-09-03 las tres la
-- tienen puesta a propósito: se quitó, rompió el escaparate y se devolvió.
-- ============================================================================

-- 1) Comprobación previa. Tiene que devolver CERO filas.
SELECT id, name FROM volunteer_category WHERE delivery_prep = 1;

-- 2) Sólo si lo anterior salió vacío.
ALTER TABLE volunteer_category DROP delivery_prep;
