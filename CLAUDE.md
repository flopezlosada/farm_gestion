# CLAUDE.md — contexto para agentes de Claude Code

Este fichero te pone al día. Léelo antes de tocar nada. El README.md tiene
información operativa (cómo levantar el entorno); aquí van las cosas que no
caben en el README pero conviene que sepas: por qué se ha decidido cada cosa,
dónde estamos, qué viene, y qué hay que cuidar.

## Quién es el usuario

Paco — `paco.lopez@toq.io`. Trabaja en la asociación **CSA Vega de Jarama**
(csavegadejarama.org). Tiene perfil técnico, escribe Symfony/PHP. Habla en
español; las respuestas y commits van en español.

## Qué es este proyecto

Software de gestión interna para la asociación: socixs, modalidades de cesta
(semanal/quincenal/mensual), huevos por frecuencia/cantidad, calendario de
reparto semanal, granja propia (huerta + gallinas + huevos + cosechas).

**Importante: la app también sirve el frontend público** del blog de
csavegadejarama.org. No es sólo gestión interna. `BlogController` tiene
métodos `frontend_index`, `show`, `show_category` y `templates/Blog/*` +
`templates/base_blog.html.twig` se renderizan para visitantes anónimos.
Esto afecta a decisiones (ej. `whiteoctober/breadcrumbs-bundle` no se
puede simplemente eliminar — se usa en el blog).

**Punto de partida**: un proyecto Symfony 4.4 (`gestion_csa_4`) escrito en
2020-2021 por el propio Paco y abandonado en marzo de 2021 sin haberse llegado
a desplegar la parte de socios. Se está reviviendo y modernizando paso a paso,
con tests, en lugar de empezar de cero. La decisión de revivir vs. reescribir
se tomó tras una auditoría: el modelo de datos de socixs (Partner /
PartnerBasketShare / WeeklyBasket / WeeklyBasketGroup / BasketShare /
EggPeriod / EggAmount / Booking) está bien pensado y cubre el caso de uso.

**Realidad importante**: el sistema NUNCA se ha usado activamente para
gestionar socios. En el dump de prod hay datos cargados (246 partners,
1346 weekly_baskets) como pruebas históricas, pero la gestión real ocurre
en Excel manualmente — los datos en BBDD nunca se han ejercitado contra
usuarixs reales en flujos operativos. La parte de granja (Crops, Fowls,
Lays, Batches, Harvests…) sí está en producción y se usa a diario. Por
tanto, el código de socios es código que ha visto datos pero no flujo
real, asumimos bugs latentes, y la regla "tests al tocar cualquier cosa
de socios" es no negociable.

## Estado actual: Fases 0-4 + validación contra dump de prod

**Symfony 5.4 LTS + PHP 7.4** + MySQL 8 + Composer 2.2 LTS + Flex 1.22.
Login funciona, dashboard renderiza, las pantallas centrales de socios
y de granja cargan limpias en navegación manual. 11 smoke tests verdes.

- **Fase 0**: setup DDEV + auditoría + arreglos baseline.
- **Fase 1**: fixtures con Faker (`CatalogFixtures`, `UserFixtures`,
  `PartnerFixtures`). 30 socixs sintéticos + admin/admin + catálogos.
- **Fase 2**: 11 smoke tests funcionales bajo `tests/Controller/`,
  cubriendo login + 4 pantallas de socios + 5 de granja. BBDD `db_test`
  separada en `.env.test`.
- **Fase 3**: deps muertas fuera. Quitadas:
  `incenteev/composer-parameter-handler`, `symfony/web-server-bundle`,
  `twig/extensions` (migrado a `twig/intl-extra` + `twig/string-extra`),
  `symfony/swiftmailer-bundle` (migrado a `symfony/mailer`),
  `whiteoctober/breadcrumbs-bundle` (migrado a `mhujer/breadcrumbs-bundle`
  como drop-in en Fase 4).
- **Fase 4**: salto a Symfony 5.4 LTS. Bumps de varias deps (FOSUser
  v3.4, gedmo 3.x, doctrine/common 3.x, dompdf 2.x, etc). Migración
  mecánica de 502 alias `App:Xxx` → `\App\Entity\Xxx::class` (Doctrine 3
  no soporta alias cortos). Migración del namespace
  `Doctrine\Common\Persistence` → `Doctrine\Persistence`. FOSUser
  forzado a `noop` mailer (sigue acoplado a Swift). Tu fork
  `flopezlosada/calendar-bundle` actualizado en GitHub master con
  constraints `^5.0|^6.0` y `TreeBuilder` con nombre raíz.

**Validación contra dump de prod (mayo 2026)**: hecha. Dump cargado
en `db_prod_snapshot`, navegación manual con datos reales. Cazó 4
deudas que los smoke tests no detectaron, ya commiteadas:

- `fix(twig)` `fd68da0`: `IntlExtension` no registrado tras la
  migración a `twig/intl-extra` (Fase 3) — `format_date` desaparecía
  y rompía `Partner/show.html.twig`. Y `stfalcon_tinymce.yaml`
  perdido en algún momento → editor WYSIWYG no inicializaba.
- `fix(doctrine)` `409ea8d`: ~78 DQL con alias legacy `App:Foo` en
  22 repositorios + `BlogController` que doctrine/persistence 3.x
  ya no soporta. La migración mecánica de Fase 4 cazó alias en
  PHP pero no en strings DQL.
- `fix(form)` `f4b4ab5`: 4 forms con `'a2lix_translations_gedmo'`
  string legacy → `TranslationsType::class`. El bundle 3.x retiró
  el type específico de Gedmo.
- `chore(deps)` `0919014`: fork `flopezlosada/calendar-bundle`
  actualizado en GitHub master con 3 fixes (routing legacy,
  `extends Controller` eliminado, `Event` movido a `Contracts`).

`.env.local` quedó con un toggle comentado: BBDD `db` activa
(fixtures Faker) y `db_prod_snapshot` disponible para futuras
validaciones (ej. tras subir PHP).

**Quedan errores secundarios** identificados al navegar pero no
arreglados (Paco no usa esas pantallas activamente): `add audio`,
`add video`, `add documento` siguen rompiendo aunque
`add grouped images` funcione. Se afrontan al final si compensa.

## Hoja de ruta (orden de prioridad)

1. ~~**Fixtures con Faker**~~ ✅ Fase 1.
2. ~~**Tests funcionales baseline**~~ ✅ Fase 2.
3. ~~**Modernización de dependencias muertas**~~ ✅ Fase 3.
   `friendsofsymfony/user-bundle` y `sensio/framework-extra-bundle`
   siguen instalados (se quitan cuando rehagamos auth en Fase 7 y
   migremos a atributos PHP 8 en Fase 6).
4. ~~**Symfony 4.4 → 5.4 LTS**~~ ✅ Fase 4.
5. **PHP 7.4 → 8.3** (siguiente). Hosting cobra *extended support*
   por seguir en 7.4 → ahorro económico inmediato. Y desbloquea
   `mhujer/breadcrumbs-bundle` v1.5.9+ y `Huluti/BreadcrumbsBundle`
   si quisiéramos rama mantenida (la 1.5.7 actual aún funciona).
   Validación contra dump de prod ya hecha (ver "Estado actual").
6. **Symfony 5.4 → 6.4 → 7.2 LTS**. La última LTS publicada (a
   mayo 2026) es Symfony 7.2 (nov 2025). 6.4 sigue con soporte
   hasta nov 2027. El camino es 5.4 → 6.4 → 7.2 (dos saltos
   majors no se pueden encadenar sin pasar por la intermedia).
   Objetivo declarado por Paco: llegar a la última LTS como
   mínimo. Si la migración a 6.4 va sin sangre, seguimos a 7.2.
7. **Auth nuevo + roles**: reescribir con seguridad nativa de Symfony.
   Roles `ROLE_PARTNER`, `ROLE_GESTION`, `ROLE_ADMIN`. Magic-link login
   (sin contraseñas) pensado para la brecha digital del colectivo:
   muchxs socixs nunca han usado software, una contraseña es una
   barrera real.
8. **Acceso para socixs**: panel propio, primero solo lectura
   (calendario de cestas, próximas semanas), luego escritura (saltar
   cesta, cambiar punto de recogida puntualmente).
9. **Importación desde Excel** (sólo al ir a producción): comando
   `app:import-partners-from-xlsx`. Sólo se importa lo de socios; la
   granja ya está en MySQL.
10. **Despliegue**: hosting (LAMP clásico, sólo FTP, sin SSH; PHP
    elegible hasta 8.5; servidor sirve `public/index.php` vía
    `.htaccess` de `symfony/apache-pack`), CI/CD, copias, monitorización.

Cada fase termina con tests en verde y un tag `vX.Y` en git.

## Decisiones tomadas (no relitigues sin razón fuerte)

- **MySQL 8**, no Postgres. La prod usa MySQL, no compensa migrar.
- **DDEV** para entorno local, no instalación nativa. Reproducible,
  portable, descarta toda una clase de problemas de "en mi máquina sí".
- **PHP 7.4** durante Fase 4 (Symfony 5.4 LTS lo soporta), salto a
  PHP 8.3 en Fase 5. Razón histórica: subir PHP antes de Symfony 5.4
  estaba bloqueado por `stfalcon/tinymce-bundle` (sin versión
  Symfony 4.4 + PHP 8). Ahora ese bloqueo está resuelto.
- **FOSUserBundle 3.x con `noop` mailer** y `registration.confirmation
  = false`. FOSUser 3.x sigue acoplado a SwiftMailer (que ya no
  usamos), pero el flujo de auth/email se rehace en Fase 7. Hasta
  entonces, sin emails de FOSUser.
- **Composer 2.2 LTS** se mantiene por ahora; subir cuando toque
  actualizar plugins/recipes. Ya no hay urgencia: Flex está sano.
- **Symfony Flex 1.22** (subido desde 1.12.2 en `6d4cf26`). Versiones
  anteriores de Flex 1.x apuntaban a `flex.symfony.com`, **dominio que
  ya no resuelve (NXDOMAIN global)**, lo que rompía cualquier
  `composer require/remove`. Flex 1.22 usa el endpoint de GitHub y
  funciona. Si vuelves a topar con errores DNS de `flex.symfony.com`,
  comprueba que la versión instalada de Flex sea ≥ 1.18.
- **`doctrine:schema:create` en vez de `migrations:migrate`**. La única
  migración existente (`Version20210223093641.php`) es un delta parcial
  de 48 líneas que no cubre el esquema completo. Cuando todo esté limpio,
  regenerar un baseline de migraciones.
- **Symlink `public/bundles/app -> src/Resources/public`**. Workaround
  porque las plantillas legacy piden assets en rutas estilo Symfony 2/3
  (con un AppBundle que ya no existe). Está documentado en el README.
  No está en git (`/public/bundles/` está en `.gitignore`) — se crea a
  mano al hacer setup. Cuando se modernice el frontend: mover assets a
  `public/` o `assets/` y actualizar plantillas.
  **Hook `restore-legacy-assets-symlink`** en `composer.json`
  (commit `94ee36a`) lo recrea automáticamente tras
  `composer install/update` (esos disparan `assets:install` por
  Flex y luego el hook) — cualquier `composer require/remove`
  borra `public/bundles/` y dejaría la web sin CSS si no fuera
  por este hook. **Atención**: si ejecutas `bin/console assets:install`
  manualmente, el hook NO se dispara y el symlink se queda borrado.
  En ese caso, recrearlo a mano:
  `ln -s ../../src/Resources/public public/bundles/app`.
  Si en algún momento ves la web sin estilos: comprueba que el
  symlink existe.
- **`ONLY_FULL_GROUP_BY` desactivado en MySQL** vía
  `.ddev/mysql/no_strict_group_by.cnf`. El código viejo tiene queries
  con `GROUP BY` incompletos. Cuando se modernicen los repositorios,
  reescribir queries y reactivar el modo estricto.
- **`ROLE_ADMIN` incluye `ROLE_COOP`** en `security.yaml`. La jerarquía
  original tenía dos ramas inconexas: super admin no podía entrar en
  `/gestion/`. Esto se rediseña al rehacer la auth con roles propios.
- **`DATABASE_URL` viene de `.env`**, no del antiguo
  `config/packages/doctrine.yaml` con `url:` hardcodeada. La config
  doctrine ahora usa `%env(resolve:DATABASE_URL)%`.

## Tareas pendientes que NO se te pueden olvidar

- **#15 (crítica)**: el primer commit del repo (`acc543c`, baseline)
  contiene credenciales reales de producción dentro de
  `config/packages/doctrine.yaml`: usuario `gallinas`, password
  `REDACTED`, BBDD `gestioncsa`. Los commits posteriores las
  retiran del fichero, pero siguen en historia git. **Antes de subir
  el repo a cualquier remoto** hay que (1) rotar la password en el
  servidor de producción y (2) `git filter-repo` para limpiar la
  historia. Mientras el repo está sólo en disco externo no hay leak.
- **#16**: queries con `GROUP BY` incompletos (al menos en
  `LayRepository`, probablemente más). Hay que arreglarlas y reactivar
  `ONLY_FULL_GROUP_BY` cuando se modernicen los repositorios.

## Convenciones

- **Commits en español**, [Conventional Commits](https://www.conventionalcommits.org/es/v1.0.0/)
  (`feat:`, `fix:`, `chore:`, `test:`, `docs:`). Mensaje con un cuerpo
  que explica el "por qué", no sólo el "qué".
- **Commits pequeños** que se puedan revertir aisladamente.
- **Nada de credenciales** en repo. `.env` con defaults DDEV-friendly
  (que son públicos por diseño en DDEV: `db:db@db:3306/db`),
  `.env.local` para overrides personales.
- **Branch principal**: `main`. Ramas `feat/*`, `fix/*`, `chore/*`.
- **Tests obligatorios** al tocar código existente. Sin excepciones.
- **Tono al hablar con el usuario**: prosa antes que listas, evitar
  over-formatting, ser honesto sobre limitaciones, no sobrevender.
  Si una recomendación cambia, decirlo claro y explicar por qué.

## Estructura del proyecto (resumen)

- `src/Entity/` — 74 entidades Doctrine. La granja (Crop, Fowl, Lay,
  Harvest, Batch…) y socios (Partner, BasketShare, WeeklyBasket…).
- `src/Controller/` — 53 controladores, todos bajo `/gestion/...`.
- `src/Repository/` — repositorios Doctrine. Aquí viven las queries
  raras con `GROUP BY` incompletos.
- `src/Migrations/` — sólo 1 migración, parcial. No es fuente de verdad.
- `templates/` — 325 plantillas Twig. Plantillas legacy de la era
  Symfony 2/3 con un theme tipo SB Admin 2.
- `assets/` — entry point moderno de Webpack Encore (vacío de
  contenido real).
- `src/Resources/public/` — assets legacy (bootstrap, jquery,
  metisMenu, datatables, fontawesome). Symlinkados a
  `public/bundles/app/`.
- `tests/Controller/` — 11 smoke tests funcionales (Fase 2). Login,
  4 pantallas de socios, 5 de granja. Helper `AbstractAuthenticatedTest`
  centraliza el login admin/admin. Correr con
  `ddev exec "php bin/phpunit tests/Controller/"`.
- `config/packages/` — configuración de bundles. `security.yaml`,
  `doctrine.yaml`, etc.

## Recursos disponibles fuera del repo

- **Dump de producción** en `~/Downloads/` (Mac de Paco). Útil para:
  validar migraciones contra datos reales, preparar la importación a
  producción (Fase 9), o testing exhaustivo. **Nunca importarlo a la
  BBDD `db` (dev) ni `db_test`** — contiene datos personales reales
  (LOPD/GDPR). Si hace falta, crear `db_prod_snapshot` aislada.
- **Mailpit en DDEV**: `http://csa-vega.ddev.site:8025`. Captura
  cualquier email enviado por la app en local. `MAILER_DSN` ya apunta
  ahí.

## Cómo trabajar conmigo (Paco)

- Soy desarrollador Symfony, hablamos sin azúcar.
- Le gusta entender el "por qué" antes de aplicar el "qué".
- No le gusta el over-formatting innecesario, pero sí estructura clara
  cuando es útil.
- Cuando algo se rompe, mando el output completo y rápido. No abrir
  hipótesis sin datos.
