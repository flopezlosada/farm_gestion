-- ============================================================================
-- Registro de vencimientos (DDL).
--
-- Dos tablas nuevas para vigilar lo que la asociación tiene que mantener
-- vigente: convenios de tierra, REGA/REGEPA, pólizas, concesiones de pozos,
-- planes de prevención, el nombramiento de la junta directiva.
--
--   `obligation`       la cosa que hay que mantener vigente. Permanente: un
--                      convenio no desaparece cuando se renueva.
--   `obligation_term`  un periodo de validez. RENOVAR ES AÑADIR UNA FILA AQUÍ,
--                      no editar una fecha. Por eso el vencimiento vigilado es
--                      el MAX(ends_on) de la obligación y no una columna: con
--                      una fecha suelta, alguien tendría que acordarse de
--                      moverla el día de la renovación, que es justo el olvido
--                      que este registro viene a evitar.
--
-- ANTES de aplicarlo, contrástalo con lo que Doctrine espera:
--   ddev exec bin/console doctrine:schema:update --dump-sql | grep obligation
-- Y NUNCA `doctrine:schema:update --force`: arrastraría el drift de índices
-- preexistente (ver CLAUDE.md / memoria schema_drift_anotaciones_vs_db).
--
-- Aplicar a las TRES BBDD de trabajo: db (sandbox), db_prod_snapshot (golden)
-- y db_test.
--   ddev mysql db               < dev-docs/schema/obligations.sql
--   ddev mysql db_prod_snapshot < dev-docs/schema/obligations.sql   # tras esto, bin/db-backup
--   ddev mysql db_test          < dev-docs/schema/obligations.sql
--
-- En PRODUCCIÓN, a mano por phpMyAdmin. Las tablas nacen vacías: el contenido
-- lo mete una persona desde /gestion/vencimientos, no hay nada que migrar.
--
-- ORDEN RESPECTO AL CÓDIGO: 🔴 LAS TABLAS VAN ANTES. La pantalla las consulta
-- al pintarse y la tarea programada al arrancar, así que con el código
-- desplegado y sin tablas la sección da 500 y el primer tick de la mañana
-- registra la tarea como fallida.
--
-- La clave ajena a `fos_user` va con ON DELETE SET NULL: dar de baja a quien
-- llevaba un convenio no debe borrar el convenio, sólo dejarlo sin responsable
-- (y bien visible en la pantalla, que es lo que se quiere). La de
-- `obligation_term` va en CASCADE porque un periodo sin su obligación no
-- significa nada.
-- ============================================================================

CREATE TABLE obligation (
    id INT AUTO_INCREMENT NOT NULL,
    name VARCHAR(255) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    counterparty VARCHAR(255) DEFAULT NULL,
    reference VARCHAR(120) DEFAULT NULL,
    notes LONGTEXT DEFAULT NULL,
    document_url VARCHAR(500) DEFAULT NULL,
    responsible_id INT DEFAULT NULL,
    archived TINYINT(1) DEFAULT 0 NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX IDX_obligation_archived (archived),
    INDEX IDX_obligation_kind (kind),
    INDEX IDX_obligation_responsible (responsible_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE obligation_term (
    id INT AUTO_INCREMENT NOT NULL,
    obligation_id INT NOT NULL,
    starts_on DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
    ends_on DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
    document_url VARCHAR(500) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX IDX_obligation_term_ends (ends_on),
    INDEX IDX_obligation_term_obligation (obligation_id, ends_on),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE obligation
    ADD CONSTRAINT FK_obligation_responsible FOREIGN KEY (responsible_id) REFERENCES fos_user (id) ON DELETE SET NULL;

ALTER TABLE obligation_term
    ADD CONSTRAINT FK_obligation_term_obligation FOREIGN KEY (obligation_id) REFERENCES obligation (id) ON DELETE CASCADE;
