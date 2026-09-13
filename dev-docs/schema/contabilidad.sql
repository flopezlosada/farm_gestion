-- Módulo de contabilidad y presupuestos: libro de caja (cuentas, partidas, apuntes)
-- y presupuesto anual por partida y mes.
--
-- Sustituye el libro que hoy se lleva en Excel, donde cada apunte se teclea TRES
-- veces (hoja de la cuenta, hoja del mes y hoja resumen) y el total del mes una
-- cuarta, en la pestaña de seguimiento del presupuesto. Aquí se teclea una vez y
-- todo lo demás son consultas.
--
-- Notas de diseño que explican el esquema:
--
--  * `account_entry.amount` lleva SIGNO (positivo entra, negativo sale) en vez de
--    dos columnas como el Excel. El saldo pasa a ser un SUM y no puede existir un
--    apunte que sea ingreso y gasto a la vez.
--
--  * El saldo de una cuenta NO se guarda: es `financial_account.opening_balance`
--    más la suma de sus apuntes desde `opening_date`.
--
--  * `budget_category` cuelga siempre de un `budget_category_group`, y son dos
--    tablas y no una con padre para que un apunte NO pueda imputarse a un grupo.
--    El grupo es además la unidad con la que se compara presupuesto y realidad: la
--    granularidad de las partidas cambia con los años y el grupo cuadra siempre.
--
--  * `account_entry.transfer_peer_id` enlaza las dos mitades de un traspaso entre
--    cuentas propias. Sin el enlace, un traspaso se queda a medias y el saldo de
--    una cuenta miente: en el libro de 2026 los traspasos descuadran 615 € por eso.
--
--  * `budget_category.name` es único DENTRO del grupo, no en toda la tabla:
--    «Formación» es una partida de ingresos (lo que se cobra por los cursos) y otra
--    de gastos (lo que se paga a quien los da).
--
-- ORDEN DE DESPLIEGUE: este SQL ANTES que el código, como siempre. Son tablas
-- nuevas y nada existente las toca, así que aplicarlo con el módulo aún apagado no
-- afecta a nada.
--
-- Aplicar a las TRES bases de trabajo: db, db_prod_snapshot (golden) y db_test.
-- En producción, cuando se encienda el módulo (el feature-flag nace apagado).

CREATE TABLE budget_line (id INT AUTO_INCREMENT NOT NULL, budget_id INT NOT NULL, category_id INT NOT NULL, month SMALLINT NOT NULL, amount NUMERIC(10, 2) NOT NULL, INDEX IDX_ABD0B6A636ABA6B8 (budget_id), INDEX IDX_ABD0B6A612469DE2 (category_id), UNIQUE INDEX uniq_budget_line_budget_category_month (budget_id, category_id, month), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE budget_category (id INT AUTO_INCREMENT NOT NULL, group_id INT NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) NOT NULL, sort_order SMALLINT NOT NULL, INDEX IDX_D1834865FE54D947 (group_id), UNIQUE INDEX uniq_budget_category_group_name (group_id, name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE financial_account (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, kind SMALLINT NOT NULL, iban VARCHAR(34) DEFAULT NULL, opening_balance NUMERIC(10, 2) NOT NULL, opening_date DATE NOT NULL, is_active TINYINT(1) NOT NULL, sort_order SMALLINT NOT NULL, UNIQUE INDEX UNIQ_2FF514CE5E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE account_entry (id INT AUTO_INCREMENT NOT NULL, account_id INT NOT NULL, category_id INT NOT NULL, transfer_peer_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, entry_date DATE NOT NULL, concept VARCHAR(255) NOT NULL, provider_name VARCHAR(150) DEFAULT NULL, invoice_number VARCHAR(50) DEFAULT NULL, amount NUMERIC(10, 2) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_1DAE44019B6B5FBA (account_id), UNIQUE INDEX UNIQ_1DAE44019F6C6FFF (transfer_peer_id), INDEX IDX_1DAE4401B03A8386 (created_by_id), INDEX idx_account_entry_date (entry_date), INDEX idx_account_entry_account_date (account_id, entry_date), INDEX idx_account_entry_category (category_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE budget (id INT AUTO_INCREMENT NOT NULL, budget_year SMALLINT NOT NULL, name VARCHAR(100) NOT NULL, is_approved TINYINT(1) NOT NULL, approved_at DATE DEFAULT NULL, notes LONGTEXT DEFAULT NULL, opening_cash NUMERIC(10, 2) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_budget_year_name (budget_year, name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
CREATE TABLE budget_category_group (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, kind SMALLINT NOT NULL, sort_order SMALLINT NOT NULL, UNIQUE INDEX UNIQ_A4E563EA5E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE budget_line ADD CONSTRAINT FK_ABD0B6A636ABA6B8 FOREIGN KEY (budget_id) REFERENCES budget (id) ON DELETE CASCADE;
ALTER TABLE budget_line ADD CONSTRAINT FK_ABD0B6A612469DE2 FOREIGN KEY (category_id) REFERENCES budget_category (id) ON DELETE RESTRICT;
ALTER TABLE budget_category ADD CONSTRAINT FK_D1834865FE54D947 FOREIGN KEY (group_id) REFERENCES budget_category_group (id) ON DELETE RESTRICT;
ALTER TABLE account_entry ADD CONSTRAINT FK_1DAE44019B6B5FBA FOREIGN KEY (account_id) REFERENCES financial_account (id) ON DELETE RESTRICT;
ALTER TABLE account_entry ADD CONSTRAINT FK_1DAE440112469DE2 FOREIGN KEY (category_id) REFERENCES budget_category (id) ON DELETE RESTRICT;
ALTER TABLE account_entry ADD CONSTRAINT FK_1DAE44019F6C6FFF FOREIGN KEY (transfer_peer_id) REFERENCES account_entry (id) ON DELETE SET NULL;
ALTER TABLE account_entry ADD CONSTRAINT FK_1DAE4401B03A8386 FOREIGN KEY (created_by_id) REFERENCES fos_user (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Catálogo inicial de partidas
-- ---------------------------------------------------------------------------
-- Sale de las 52 etiquetas realmente usadas en el libro de 2026, no de las del
-- presupuesto: el libro es el vocabulario que se usa a diario. Se han fusionado
-- las que eran la misma cosa mal escrita (TRASPASO/TRAPASO, LA CERRADA/LA
-- CEERADA/CERRADA, SEGURO/SEGUROS, GRUPO CONSUMO/GRUPO DE CONSUMO) y las que
-- eran proveedores usados como partida (HERMANOS SALAZAR, FERRETERIA → Huerta).
--
-- Los subconceptos de la feria (artesanía, paella, encurtidos, bebida) van a una
-- sola partida «Feria»: nadie presupuesta la paella, y el detalle no se pierde
-- porque sigue en el concepto de cada apunte. GC-CSA se deja aparte de «Grupo de
-- consumo» a propósito, porque aún no está claro si son la misma cosa: separar
-- es reversible, fusionar pierde información.
--
-- Las CUENTAS (Fiare, La Caixa, cajas de efectivo) NO se siembran aquí: llevan
-- saldos reales y el nombre de una persona, y este repositorio es público. Se
-- dan de alta desde la pantalla de cuentas.

INSERT INTO budget_category_group (name, kind, sort_order) VALUES
    ('INGRESOS', 1, 10),
    ('ADMINISTRACIÓN', 2, 20),
    ('HUERTA', 2, 30),
    ('SUELDOS', 2, 40),
    ('HUEVOS', 2, 50),
    ('VARIOS', 2, 60),
    ('INVERSIÓN', 3, 70),
    ('FINANCIACIÓN', 4, 80),
    ('TRASPASOS', 5, 90);

INSERT INTO budget_category (group_id, name, description, is_active, sort_order) VALUES
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Cuotas de socixs', 'La remesa mensual y las transferencias de quien no domicilia. Las devoluciones van aquí en negativo.', 1, 10),
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Cuota de mantenimiento', 'La cuota semestral de pertenencia a la asociación, aparte de la cesta.', 1, 20),
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Inscripciones', 'Lo que paga quien entra en la asociación.', 1, 30),
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Donaciones y subvenciones', NULL, 1, 40),
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Formación', 'Lo que se cobra por los cursos. Lo que se paga a quien los da va en gastos.', 1, 50),
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Grupo de consumo', 'Lo que transfieren las socias por sus pedidos.', 1, 60),
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Grupo de consumo en el local', 'Lo que en el libro figura como GC-CSA. Pendiente de confirmar en qué se diferencia del anterior.', 1, 70),
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Venta de huevos', 'Las docenas que se venden sueltas, no las de las cestas.', 1, 80),
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Feria', 'Todo lo que entra por la feria: artesanía, comida, bebida.', 1, 90),
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Venta de tierra', NULL, 1, 100),
    ((SELECT id FROM budget_category_group WHERE name = 'INGRESOS'), 'Varios', 'Lo que entra y no encaja en ninguna otra: bote del local, visitas didácticas…', 1, 110),

    ((SELECT id FROM budget_category_group WHERE name = 'ADMINISTRACIÓN'), 'Administración y gestoría', NULL, 1, 10),
    ((SELECT id FROM budget_category_group WHERE name = 'ADMINISTRACIÓN'), 'Comisiones bancarias', 'Incluye la comisión de cada recibo devuelto.', 1, 20),
    ((SELECT id FROM budget_category_group WHERE name = 'ADMINISTRACIÓN'), 'Teléfono', NULL, 1, 30),
    ((SELECT id FROM budget_category_group WHERE name = 'ADMINISTRACIÓN'), 'Local', 'Alquiler y suministros del local.', 1, 40),
    ((SELECT id FROM budget_category_group WHERE name = 'ADMINISTRACIÓN'), 'Seguros', NULL, 1, 50),
    ((SELECT id FROM budget_category_group WHERE name = 'ADMINISTRACIÓN'), 'Impuestos', 'Incluye el IVA liquidado.', 1, 60),
    ((SELECT id FROM budget_category_group WHERE name = 'ADMINISTRACIÓN'), 'Formación', 'Lo que se paga a quien da los cursos.', 1, 70),
    ((SELECT id FROM budget_category_group WHERE name = 'ADMINISTRACIÓN'), 'Prevención de riesgos', NULL, 1, 80),
    ((SELECT id FROM budget_category_group WHERE name = 'ADMINISTRACIÓN'), 'Alquiler de la finca', 'Va en administración, no en huerta, porque así lo agrupa el presupuesto y el grupo es la unidad con la que se compara.', 1, 90),

    ((SELECT id FROM budget_category_group WHERE name = 'HUERTA'), 'Huerta', 'Consumibles, herramienta y ferretería del día a día.', 1, 10),
    ((SELECT id FROM budget_category_group WHERE name = 'HUERTA'), 'Semillas y plantel', NULL, 1, 20),
    ((SELECT id FROM budget_category_group WHERE name = 'HUERTA'), 'Gasolina', NULL, 1, 30),
    ((SELECT id FROM budget_category_group WHERE name = 'HUERTA'), 'Mantenimiento y reparaciones', 'Maquinaria y vehículos, ITV incluida.', 1, 40),
    ((SELECT id FROM budget_category_group WHERE name = 'HUERTA'), 'Estiércol y biopreparados', NULL, 1, 50),

    ((SELECT id FROM budget_category_group WHERE name = 'SUELDOS'), 'Nóminas', NULL, 1, 10),
    ((SELECT id FROM budget_category_group WHERE name = 'SUELDOS'), 'Seguridad Social', NULL, 1, 20),
    ((SELECT id FROM budget_category_group WHERE name = 'SUELDOS'), 'IRPF', 'Las retenciones que se ingresan a Hacienda.', 1, 30),

    ((SELECT id FROM budget_category_group WHERE name = 'HUEVOS'), 'Grano y huevos comprados', 'El pienso de las gallinas y los huevos que hay que comprar fuera.', 1, 10),

    ((SELECT id FROM budget_category_group WHERE name = 'VARIOS'), 'Transporte', 'Lo que se paga por llevar las cestas a los nodos.', 1, 10),
    ((SELECT id FROM budget_category_group WHERE name = 'VARIOS'), 'Grupo de consumo', 'Lo que se paga a los productores contra factura.', 1, 20),
    ((SELECT id FROM budget_category_group WHERE name = 'VARIOS'), 'Feria', 'Lo que cuesta montar la feria.', 1, 30),
    ((SELECT id FROM budget_category_group WHERE name = 'VARIOS'), 'Perros', 'Veterinario y comida de los perros de la finca.', 1, 40),
    ((SELECT id FROM budget_category_group WHERE name = 'VARIOS'), 'Voluntariado internacional', 'Gastos de quien viene de estancia (woofers).', 1, 50),
    ((SELECT id FROM budget_category_group WHERE name = 'VARIOS'), 'Tierra', 'Acondicionamiento de las parcelas. El alquiler de la finca va en administración, como en el presupuesto.', 1, 55),
    ((SELECT id FROM budget_category_group WHERE name = 'VARIOS'), 'Varios', NULL, 1, 60),

    ((SELECT id FROM budget_category_group WHERE name = 'INVERSIÓN'), 'Inversión', 'Obra, maquinaria y animales: lo que dura más de un año.', 1, 10),

    ((SELECT id FROM budget_category_group WHERE name = 'FINANCIACIÓN'), 'Préstamos recibidos', 'El dinero que entra al pedir un préstamo.', 1, 10),
    ((SELECT id FROM budget_category_group WHERE name = 'FINANCIACIÓN'), 'Cuotas de préstamo', 'Lo que se devuelve cada mes.', 1, 20),

    ((SELECT id FROM budget_category_group WHERE name = 'TRASPASOS'), 'Traspaso entre cuentas', 'Mover dinero de una cuenta propia a otra. No es ingreso ni gasto.', 1, 10);
