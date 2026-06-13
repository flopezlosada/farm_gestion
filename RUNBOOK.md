# RUNBOOK — Operación y despliegue en producción

Guía operativa para cuando esto está **en producción con gente real dentro**.
No explica cómo programar (eso es `CLAUDE.md`) ni cómo levantar el entorno
local (eso es `README.md`). Aquí está lo que necesitas el día que hay que
desplegar, hacer una copia, o algo se rompe y hay que arreglarlo rápido.

> **Dos riesgos estructurales que conviene tener presentes** (no son un fallo
> de esta guía, son del montaje actual; ver §7):
> 1. **No hay rollback automático de código.** El deploy sube ficheros por FTP
>    con `lftp mirror`; no hay versiones en el servidor. "Volver atrás" =
>    re-desplegar una versión anterior (§5).
> 2. **El backup de la BBDD de producción es manual** (phpMyAdmin del hosting).
>    `bin/db-backup` respalda la base LOCAL, no la de producción (§6).

---

## 1. Mapa: dónde vive cada cosa

| Pieza | Dónde |
|---|---|
| Web pública + gestión | Hosting (cdmon), dominio `csavegadejarama.org` |
| Código en el servidor | Carpeta `gestion_csa_4/` del FTP |
| Acceso al servidor | **Solo FTP** (no hay SSH) |
| Base de datos de producción | MySQL del hosting · se administra por **phpMyAdmin** |
| Despliegue | GitHub Actions → workflow **"Deploy a producción"** (`deploy.yml`), **manual** |
| Credenciales FTP del deploy | GitHub → *Settings → Secrets*: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` |

**Accesos — NO se escriben aquí.** Las credenciales del hosting viven en el
**gestor de contraseñas** del proyecto (entrada "CSA Vega · hosting"), nunca en
este fichero ni en el repo. El gestor debe tener: panel de cdmon (URL + usuario),
phpMyAdmin (URL + nombre de la BBDD de prod), credenciales FTP y el contacto de
soporte del hosting.

- [ ] Pendiente (Paco): confirmar si el hosting hace copias automáticas de
      BBDD/ficheros, cada cuánto y cómo se restauran desde el panel.

---

## 2. Antes de CADA despliegue (checklist)

1. **Tests en verde.** El workflow de tests (`tests.yml`) corre en cada push/PR.
   No despliegues con la suite roja.
2. **Probado en staging.** El flujo es: desplegar a staging (`deploy-staging.yml`,
   permite elegir rama), validar a mano, y solo entonces ir a producción.
3. **Backup de la BBDD de producción** (§6). Hazlo SIEMPRE antes de desplegar
   algo que toque el esquema o los datos.
4. **Anota a qué versión volver.** Apunta el commit actualmente en producción
   (el último deploy verde) por si hay que revertir.
5. **¿Hay cambios de esquema?** Si el código nuevo añade columnas/tablas, hay
   que aplicarlas en la BBDD de producción ANTES o a la vez (§4, error "Column
   not found").

---

## 3. Desplegar a producción

1. GitHub → pestaña **Actions** → workflow **"Deploy a producción"**.
2. **Run workflow** (rama `main`). Es manual a propósito: el push automático se
   retiró.
3. El workflow: instala dependencias de producción, compila los assets
   (`npm run build`), **copia los assets legacy** a `public/bundles/app`
   (el FTP no transmite symlinks) y sube por FTP solo lo más nuevo
   (`mirror -R --only-newer`).
4. Cuando termine en verde, **comprueba la web a mano**: entra, haz login, abre
   una pantalla de cada área (reparto, ficha de socio, cosechas). No te fíes del
   ✅ del workflow: el FTP puede subir bien y aun así dejar algo a medias (§5).

> El deploy puede tardar bastante aunque cambien pocos ficheros: `lftp mirror`
> recorre el árbol entero por FTP. Es lento, no está colgado.

---

## 4. Errores conocidos y cómo arreglarlos

### La web sale SIN ESTILOS
Falta `public/bundles/app` en el servidor (los assets legacy). El deploy los
copia; si aun así falta, súbelos por FTP a `gestion_csa_4/public/bundles/app/`
desde tu copia local de `src/Resources/public/`.

### Imágenes rotas / 404 en uploads
`public/uploads/` y `media/cache/` están gitignored: **el deploy nunca sube las
imágenes**. Súbelas por FTP a mano (`public/uploads/`); la caché de miniaturas se
regenera sola.

### Error "Column not found" / `SQLSTATE[42S22]`
El código nuevo espera una columna/tabla que la BBDD de producción no tiene
(drift de esquema). Aplica el `ALTER`/`CREATE` que falte **a mano por phpMyAdmin**.
⚠️ **NUNCA** corras `doctrine:schema:update --force`: borraría índices de
producción (hay drift conocido anotaciones↔BBDD). Aplica solo los statements de
lo nuevo, uno a uno.

### La web escupe código fuente de un `.twig` antes del HTML
Corrupción de transferencia FTP (un `.php` quedó con contenido de otro fichero
por una *race* de `lftp --parallel`). Diagnóstico:
1. `curl -s <URL>` y mira el body crudo: ¿el "prefijo" es contenido literal de
   un `.twig`?
2. Symfony suele nombrar el fichero corrupto en el mensaje de error.
3. **Re-sube ese `.php` por FTP a mano** y **borra `var/cache/prod/`** en el
   servidor (§5). Verifica.

### Error 500 genérico
Mira `var/log/prod/` en el servidor (por FTP). Con `APP_DEBUG=0` la web no
muestra el detalle; el log sí.

---

## 5. Si algo sale mal — revertir

### Limpiar la caché de producción (lo primero a probar)
Muchos fallos tras un deploy se arreglan borrando la caché compilada:
**borra la carpeta `gestion_csa_4/var/cache/prod/` por FTP**. Symfony la
regenera en la siguiente petición. (Sin SSH no hay `cache:clear`; borrar la
carpeta es el equivalente.)

### Volver atrás el CÓDIGO
No hay rollback automático. Para revertir:
1. En local, sitúate en la última versión buena (`git checkout <commit-bueno>`),
   o revierte el commit problemático en `main`.
2. Vuelve a lanzar **"Deploy a producción"**.
3. ⚠️ Limitación: `mirror --only-newer` **no borra** del servidor ficheros que
   ya no existen en la versión vieja. Si el deploy malo añadió ficheros, pueden
   quedar huérfanos. Si sospechas de eso, borra a mano por FTP los ficheros
   sobrantes.

### Restaurar la BASE DE DATOS
Si el problema corrompió datos: restaura el dump que hiciste en §2 (o la copia
automática del hosting, si la hay) **por phpMyAdmin** (importar el `.sql`).
Por eso el backup previo de §2 no es opcional.

---

## 6. Copias de seguridad

### Producción (la importante)
El backup de la BBDD de producción se hace **por phpMyAdmin del hosting**:
exporta la base completa a `.sql` antes de cualquier cambio de riesgo.
**TODO (Paco):** documentar aquí los pasos exactos del panel y si hay copias
automáticas.

### Local (golden) — NO es backup de producción
`bin/db-backup` vuelca el **golden local** (`db_prod_snapshot`, la fuente de
verdad de trabajo) a `~/csa-backups/` fuera del repo. Llévalo SIEMPRE tras tocar
el golden. Esos dumps tienen **datos personales reales (LOPD)**: nunca van al
repo ni a sitios externos.

---

## 7. Reglas de oro (no te las saltes)

- **Nada de datos reales fuera de tu máquina** sin anonimizar (`app:anonymize-staging`
  faketea emails de no-testers). Los dumps con PII viven solo en local y en
  `~/csa-backups/`.
- **Backup de BBDD de producción antes de cualquier deploy con cambios de datos/esquema.**
- **Nunca `doctrine:schema:update --force` contra producción** (§4).
- **Tras tocar el golden local, `bin/db-backup`.**
- **Credenciales:** en el servidor, `config`/`.env` con credenciales de BBDD
  viven en archivo plano legible (deuda #17). Mitigación pendiente: `chmod 600`
  o `composer dump-env prod`. No metas credenciales reales en el repo.

---

## 8. Pendiente de endurecer (deuda operativa)

- Rollback de código real (hoy es re-deploy manual; `mirror` no versiona ni borra huérfanos).
- Backup de BBDD de producción automatizado y probado (hoy es phpMyAdmin manual).
- Riesgo de corrupción de `lftp --parallel` no resuelto del todo: valorar
  `--parallel=1`, verificación de checksums o `php -l` remoto post-deploy.
- Limpieza de `var/cache/prod` sin SSH: valorar añadir `cache:clear` a la
  whitelist de la pantalla de diagnóstico (`/gestion/configuracion/diagnostico`).
