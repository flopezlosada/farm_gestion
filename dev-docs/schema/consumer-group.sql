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
