-- Encuestas internas a lxs socixs: la encuesta, sus preguntas y opciones, las
-- respuestas (anónimas) y quién ha participado (sin saber qué contestó).
--
-- POR QUÉ LLEGA AHORA. Estas tablas se crearon a mano en junio de 2026 en las
-- bases locales y nunca se escribieron aquí, así que cualquier entorno montado
-- desde el repositorio no las tiene. Es el esquema TAL COMO ESTÁ en `db`, más la
-- columna `announced_at` (ver survey-announced-at.sql).
--
-- EL ANONIMATO VIVE EN EL ESQUEMA: `survey_answer` no tiene ninguna columna que
-- apunte a la socia ni una fecha que permita cruzarla con `survey_participation`.
-- No añadir ninguna de las dos.
--
-- EL ANTIDUPLICADO TAMBIÉN: `uniq_survey_partner`. El código lo da por puesto y
-- captura su violación; sin él, un doble envío contaría dos veces.
--
-- SEGURO PEGADO ENTERO: todo es CREATE TABLE IF NOT EXISTS. Donde ya existan
-- las tablas no hace nada — y entonces lo que falta es survey-announced-at.sql.
--
-- ORDEN DE DESPLIEGUE: antes que el código, y antes de encender el
-- feature-flag `feature.surveys`. Con el flag encendido y sin tablas, el panel
-- de la socia revienta.

CREATE TABLE IF NOT EXISTS survey (
    id INT AUTO_INCREMENT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description LONGTEXT DEFAULT NULL,
    status VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    closes_at DATETIME DEFAULT NULL,
    archived TINYINT(1) DEFAULT 0 NOT NULL,
    announced_at DATETIME DEFAULT NULL COMMENT 'Cuándo se avisó a las socias de que está abierta',
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS survey_question (
    id INT AUTO_INCREMENT NOT NULL,
    survey_id INT NOT NULL,
    text VARCHAR(500) NOT NULL,
    type VARCHAR(20) NOT NULL,
    position INT NOT NULL,
    required TINYINT(1) NOT NULL,
    INDEX IDX_EA000F69B3FE509D (survey_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_EA000F69B3FE509D FOREIGN KEY (survey_id) REFERENCES survey (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS survey_question_option (
    id INT AUTO_INCREMENT NOT NULL,
    question_id INT NOT NULL,
    label VARCHAR(255) NOT NULL,
    position INT NOT NULL,
    INDEX IDX_F50FFD8C1E27F6BF (question_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_F50FFD8C1E27F6BF FOREIGN KEY (question_id) REFERENCES survey_question (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS survey_answer (
    id INT AUTO_INCREMENT NOT NULL,
    question_id INT NOT NULL,
    option_id INT DEFAULT NULL,
    value_int INT DEFAULT NULL,
    value_text LONGTEXT DEFAULT NULL,
    INDEX IDX_F2D382491E27F6BF (question_id),
    INDEX IDX_F2D38249A7C41D6F (option_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_F2D382491E27F6BF FOREIGN KEY (question_id) REFERENCES survey_question (id) ON DELETE CASCADE,
    CONSTRAINT FK_F2D38249A7C41D6F FOREIGN KEY (option_id) REFERENCES survey_question_option (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS survey_participation (
    id INT AUTO_INCREMENT NOT NULL,
    survey_id INT NOT NULL,
    partner_id INT NOT NULL,
    submitted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_survey_partner (survey_id, partner_id),
    INDEX IDX_C04E61B7B3FE509D (survey_id),
    INDEX IDX_C04E61B79393F8FE (partner_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_C04E61B7B3FE509D FOREIGN KEY (survey_id) REFERENCES survey (id) ON DELETE CASCADE,
    CONSTRAINT FK_C04E61B79393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
