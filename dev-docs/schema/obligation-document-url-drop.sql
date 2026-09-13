-- ============================================================================
-- Retirada de la columna del enlace (DDL, paso 2 de 2).
--
-- 🔴 ESTO VA DESPUÉS DEL CÓDIGO, no antes. Mientras la versión desplegada siga
-- mapeando `document_url`, borrarla tumba la sección entera con un error de
-- columna desconocida. El orden correcto es: aplicar
-- `obligation-documents.sql` → desplegar el código → aplicar esto.
--
-- No se pierde nada: ninguna ficha llegó a guardar un enlace (0 de 18
-- obligaciones y 0 de 10 periodos comprobados antes del cambio). Si en el
-- entorno donde lo apliques no fuera así, míralo antes:
--   SELECT id, name, document_url FROM obligation WHERE document_url <> '';
-- ============================================================================

ALTER TABLE obligation DROP COLUMN document_url;
ALTER TABLE obligation_term DROP COLUMN document_url;
