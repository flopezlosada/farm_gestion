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
