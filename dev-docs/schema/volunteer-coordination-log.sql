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
