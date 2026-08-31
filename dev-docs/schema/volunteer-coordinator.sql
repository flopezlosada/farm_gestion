-- Quién coordina UNA tarea de voluntariado.
--
-- No confundir con quien coordina un ÁREA (volunteer_category_coordinator):
-- aquello es ManyToMany y va contra `fos_user`, porque quien coordina un área
-- necesita una cuenta con la que entrar y no siempre es socix. Esto va contra
-- `partner` porque su única razón de ser es que se le computen horas, y las
-- horas cuelgan de un socix con ficha.
--
-- ON DELETE SET NULL y no CASCADE: si un socix se borra, la tarea sobrevive sin
-- coordinador. Perder la referencia es aceptable; perder la tarea y con ella
-- las horas de todo el mundo, no.
--
-- Aplicar en las tres bases de trabajo: db, db_prod_snapshot y db_test.

ALTER TABLE volunteer_offer
    ADD coordinator_id INT DEFAULT NULL;

ALTER TABLE volunteer_offer
    ADD CONSTRAINT fk_volunteer_offer_coordinator
    FOREIGN KEY (coordinator_id) REFERENCES partner (id) ON DELETE SET NULL;

CREATE INDEX idx_volunteer_offer_coordinator ON volunteer_offer (coordinator_id);
