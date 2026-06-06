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

## Estado actual: Fases 0-7 + sub-fases 8.1-8.4 completadas

**Symfony 7.4 LTS + PHP 8.3** + MySQL 8 + Composer 2.2 + Flex 2.x.
Auth nativa de Symfony (sin FOSUser ni Sensio FrameworkExtraBundle).
Login funciona, dashboard renderiza, las pantallas centrales de socios
y de granja cargan limpias en navegación manual contra el snapshot de
prod (246 partners, 1346 weekly_baskets, 5188 lays). 11 smoke tests
verdes hasta Fase 4 incluida — no re-validados desde Fase 5.

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
- **Validación contra dump de prod (mayo 2026)** entre Fases 4 y 5.
  Cazó 4 deudas residuales: IntlExtension olvidado, ~78 DQL alias
  `App:Foo`, 4 forms con `a2lix_translations_gedmo`, fork
  calendar-bundle con 3 problemas (routing legacy, `extends Controller`,
  `Event` movido a `Contracts`).
- **Fase 5**: PHP 7.4 → 8.3. composer.json `^7.1.3` → `^8.3`,
  `dompdf` → `^2.0` (única dep con pin a PHP 7). Sweep transitivo
  bumpó data-fixtures, migrations, ckeditor-bundle, dompdf, etc.
  Cazó 2 deudas adicionales: bundle `WhiteOctoberBreadcrumbsBundle`
  nunca registrado en `bundles.php` (la migración de Symfony 3 a 4
  lo olvidó), y `Twig\Extra\String\StringExtension` no registrado
  (mismo patrón que `IntlExtension`).
- **Fase 6**: Symfony 5.4 → 6.4 LTS. 21 paquetes pinned `5.4.*` →
  `6.4.*`. `sensio/framework-extra-bundle` `^5.5` → `^6.2`.
  `friendsofsymfony/jsrouting-bundle` `^2.4` → `^3.0` (más fix
  paralelo en el fork de calendar-bundle). Migración del Kernel a
  `RoutingConfigurator`, retirada del loader `annotation`. Migración
  de `security.yaml` al authenticator system. 376 single-colon
  controller refs en YAML/XML + 6 en templates → double colon.
  Trait compat `AbstractAppController` para mantener viva la API
  `$this->getDoctrine()` (52 controllers afectados, decisión KISS
  para no tocar 373 llamadas). 51 `@Route` docblock → `#[Route]`
  attribute en 7 controllers.
- **Sub-fase 8.1+8.2+8.3**: retira FOSUserBundle y monta auth
  nativa de Symfony. User propio sin extender FOSUser, schema
  `fos_user` reusado. password_hashers con migrate_from
  legacy_fos_sha512 → bcrypt: el primer login con sha512 rehashea
  automáticamente (sin pérdida de logins en prod). Provider entity
  + form_login con `app_login`/`app_logout` rutas propias en
  `SecurityController`. Templates de FOSUser en `templates/bundles/`
  borrados (28 ficheros).
- **Sub-fase 8.4**: retira `sensio/framework-extra-bundle`. 6
  endpoints con `@Template` migrados a `$this->render(...)` explícito.
  `@Method`/`@ParamConverter` legacy retirados.
- **Fase 7**: Symfony 6.4 → 7.4 LTS. 21 paquetes pinned `6.4.*` →
  `7.4.*`. `symfony/proxy-manager-bridge` retirado (eliminado en SF 7).
  `symfony/flex` `^1.22` → `^2.4`, `symfony/webpack-encore-bundle`
  `^1.7` → `^2.0`, `knplabs/knp-paginator-bundle` `^5.0` → `^6.0`,
  `twig/twig` y intl-extra/string-extra `^2.x` → `^3.12`,
  `symfony/translation` añadido manualmente. Más bumps en el fork
  calendar-bundle (`|^7.0` y `getConfigTreeBuilder(): TreeBuilder`).
  `EntityToIntTransformer` adopta firma estricta `mixed -> mixed`.
  240 `@Assert\X` annotations → `#[Assert\X]` attributes en 62
  archivos via Rector. Workaround: `Doctrine\Common\Annotations\AnnotationReader`
  registrado a mano en services.yaml + alias `annotation_reader`,
  porque las 74 entidades aún usan `@ORM\` annotations docblock
  (Doctrine ORM 2.x).

`.env.local` apunta a `db` (sandbox de trabajo, clon de golden). Ver
sección "LOPD/GDPR — fronteras reales" para los roles de BBDD. Durante
Fases 6, 7 y sub-fases 8.x se navegó contra el snapshot.

**Errores secundarios pendientes** identificados al navegar pero no
arreglados (no son flujos críticos del día a día):
- Plantilla pública `templates/base_front.html.twig:81` referencia
  ruta `booking_calendar` que no existe en routing (deuda preexistente,
  no regresión).
- `DefaultController::eventShow` y `GraphController::graphAverageEggWeek`
  no tienen plantilla propia y devuelven `Response('')` desde
  sub-fase 8.4. Sospechosos de ser código muerto, dejados con un
  comentario hasta confirmarlo.

**11 smoke tests aún sin re-validar** desde Fase 5 — pendiente.

## Hoja de ruta (orden de prioridad)

1. ~~**Fixtures con Faker**~~ ✅ Fase 1.
2. ~~**Tests funcionales baseline**~~ ✅ Fase 2.
3. ~~**Modernización de dependencias muertas**~~ ✅ Fase 3.
   `friendsofsymfony/user-bundle` y `sensio/framework-extra-bundle`
   siguen instalados (se quitan cuando rehagamos auth en Fase 7 y
   migremos a atributos PHP 8 en Fase 6).
4. ~~**Symfony 4.4 → 5.4 LTS**~~ ✅ Fase 4.
5. ~~**PHP 7.4 → 8.3**~~ ✅ Fase 5.
6. ~~**Symfony 5.4 → 6.4 LTS**~~ ✅ Fase 6.
7. ~~**Symfony 6.4 → 7.4 LTS**~~ ✅ Fase 7 (orden invertido con la 8
   tras topar con FOSUser bloqueando SF 7).
8. **Auth nuevo + roles** (parcial): sub-fases 8.1-8.4 hechas
   (retirada FOSUser y Sensio, auth nativa Symfony). Pendientes:
   - **8.5 Magic-link login**: usar `LoginLinkAuthenticator` nativo
     de Symfony. Pensado para la brecha digital del colectivo —
     muchxs socixs nunca han usado software, una contraseña es una
     barrera real. Crear panel de "Mi perfil" propio (el enlace está
     fuera del menú desde sub-fase 8.3).
   - **8.6 Migrar 52 controllers**: a inyección de
     `EntityManagerInterface` por parámetro del action; eliminar
     `AbstractAppController::getDoctrine()` (compat layer registrada
     en sub-fase Fase 6). Sweep mecánico, ~373 llamadas a
     `$this->getDoctrine()->getManager()` en src/Controller/.
   - **8.7 Diseño de roles**: `ROLE_PARTNER`, `ROLE_GESTION`,
     `ROLE_ADMIN` y rejerarquización. La actual cuelga ROLE_ADMIN
     de ROLE_COOP como workaround.
9. **Acceso para socixs**: panel propio, primero solo lectura
   (calendario de cestas, próximas semanas), luego escritura (saltar
   cesta, cambiar punto de recogida puntualmente).
10. **Importación desde Excel** (sólo al ir a producción): comando
   `app:import-partners-from-xlsx`. Sólo se importa lo de socios; la
   granja ya está en MySQL.
11. **Despliegue**: hosting (LAMP clásico, sólo FTP, sin SSH; PHP
    elegible hasta 8.5; servidor sirve `public/index.php` vía
    `.htaccess` de `symfony/apache-pack`), CI/CD, copias, monitorización.

Cada fase termina con tests en verde y un tag `vX.Y` en git.

## Decisiones tomadas (no relitigues sin razón fuerte)

- **MySQL 8**, no Postgres. La prod usa MySQL, no compensa migrar.
- **DDEV** para entorno local, no instalación nativa. Reproducible,
  portable, descarta toda una clase de problemas de "en mi máquina sí".
- **PHP 8.3** desde Fase 5 (subido desde 7.4). Razón histórica: subir
  PHP antes de Symfony 5.4 estaba bloqueado por `stfalcon/tinymce-bundle`.
  Hosting cobraba extended support por 7.4 → ahorro económico al
  subir.
- **Auth nativa Symfony** desde sub-fase 8.1+. FOSUserBundle retirado;
  el User propio (`App\Entity\User`) implementa `UserInterface`,
  `PasswordAuthenticatedUserInterface`, `LegacyPasswordAuthenticatedUserInterface`.
  La tabla `fos_user` se mantiene con todas sus columnas legacy
  (username_canonical, salt, confirmation_token, password_requested_at)
  hasta que rehagamos schema.
- **`Doctrine\Common\Annotations\AnnotationReader` registrado a mano**
  en `config/services.yaml` desde Fase 7 (alias `annotation_reader`).
  SF 7 ya no lo provee automáticamente. Las 74 entidades aún usan
  `@ORM\` annotations docblock (Doctrine ORM 2.x). Cuando subamos a
  Doctrine ORM 3 + entidades a `#[ORM\]` attributes, el servicio se
  retira.
- **Doctrine ORM en 2.x** a propósito. La 3.x elimina
  `Doctrine\Common\Annotations` y solo soporta attributes/XML —
  migración de las 74 entidades es trabajo separado de Fase 7.
- **`AbstractAppController` reimplementa `getDoctrine()`** vía
  `#[Required]` setter de `ManagerRegistry`. Symfony 6 retiró
  `getDoctrine()` de `AbstractController`. Migrar los 373 usos
  distribuidos en 52 controllers se difiere a sub-fase 8.6. El trait
  compat permite que la app levante sin tocar el código de los
  actions.
- **`security.password_hashers: auto`** desde Fase 6 (era `sha512`
  legacy). Las contraseñas de prod siguen hasheadas con sha512 y
  dejarán de validar al migrar el dump. Plan: en Fase 9 (al
  desplegar) un comando `app:rehash-passwords` que use
  `migrate_from` o un script ad-hoc. Mientras tanto, en
  `db_prod_snapshot` se rehashea admin/admin a mano cada vez que
  cambia el algoritmo.
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

- **#15 (resuelta 2026-05-07)**: el commit baseline tenía una password
  histórica de pruebas (no la activa en prod actual) hardcoded en
  `config/packages/doctrine.yaml`. Limpiada de toda la historia con
  `git filter-repo --replace-text` antes del primer push a remoto. Todos
  los hashes del repo cambiaron en esa operación; el baseline pasó de
  `acc543c` a `0c3313d`. La cadena ya no aparece en `git log -p --all`.
- **#16**: queries con `GROUP BY` incompletos (al menos en
  `LayRepository`, probablemente más). Hay que arreglarlas y reactivar
  `ONLY_FULL_GROUP_BY` cuando se modernicen los repositorios.
- **#17**: en producción las credenciales de BBDD viven en archivo
  plano legible. Mitigaciones para Fase 11 (despliegue): permisos
  restrictivos (`chmod 600`, owner = user del web server), variables
  de entorno del panel del hosting si lo soporta, o `composer dump-env
  prod` que compila a `.env.local.php`. No bloquea el push del repo
  (los archivos vivos del repo sólo llevan defaults DDEV inocuos), es
  deuda operativa del servidor.

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
  producción (Fase 9), o testing exhaustivo.
- **LOPD/GDPR — fronteras reales** (actualizado 2026-05-30): los datos
  personales reales **pueden** vivir en las BBDD **locales** de DDEV
  (`db`, `db_prod_snapshot`/golden, db_play) — es la misma máquina y se
  necesitan reales para reconciliar contra los listados (PDF/ODS) por
  nombre/DNI. Lo que NUNCA debe pasar:
  1. **Nada de datos reales al repo** (`.gitignore` cubre dumps y
     `.env.local`; los backups van a `~/csa-backups/` fuera del repo).
  2. **Nada de datos reales a staging/producción sin anonimizar** — usar
     `app:anonymize-staging` (faketea emails de no-testers) antes de subir.
  Roles de BBDD: `db` = sandbox de trabajo (clon de golden); `db_prod_snapshot`
  = golden (fuente de verdad, tras tocarla `bin/db-backup`); `db_test` = tests
  (aislada). `db` ya NO es Faker; si se quiere Faker, regenerar con fixtures.
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
