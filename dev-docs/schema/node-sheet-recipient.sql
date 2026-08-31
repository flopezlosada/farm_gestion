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

CREATE TABLE node_sheet_recipient (
    node_id INT NOT NULL,
    partner_id INT NOT NULL,
    INDEX IDX_node_sheet_recipient_node (node_id),
    INDEX IDX_node_sheet_recipient_partner (partner_id),
    PRIMARY KEY(node_id, partner_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- ON DELETE CASCADE en los dos lados: si se borra un nodo o una ficha de socix,
-- la fila deja de tener sentido. Perder un destinatario es inocuo (el listado cae
-- al ajuste general); dejar una fila huérfana rompería la carga de la relación.
ALTER TABLE node_sheet_recipient
    ADD CONSTRAINT FK_node_sheet_recipient_node FOREIGN KEY (node_id) REFERENCES node (id) ON DELETE CASCADE;
ALTER TABLE node_sheet_recipient
    ADD CONSTRAINT FK_node_sheet_recipient_partner FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE;
