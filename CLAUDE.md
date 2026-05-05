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

**Punto de partida**: un proyecto Symfony 4.4 (`gestion_csa_4`) escrito en
2020-2021 por el propio Paco y abandonado en marzo de 2021 sin haberse llegado
a desplegar la parte de socios. Se está reviviendo y modernizando paso a paso,
con tests, en lugar de empezar de cero. La decisión de revivir vs. reescribir
se tomó tras una auditoría: el modelo de datos de socixs (Partner /
PartnerBasketShare / WeeklyBasket / WeeklyBasketGroup / BasketShare /
EggPeriod / EggAmount / Booking) está bien pensado y cubre el caso de uso.

**Realidad importante**: la parte de socios NUNCA ha estado en producción.
Hoy se gestionan ~100 socixs en Excel, manualmente. La parte de granja
(Crops, Fowls, Lays, Batches, Harvests…) sí está en producción. Por tanto,
todo el código de socios es código que nunca se ha ejercitado contra usuarixs
reales, asumimos que tiene bugs latentes, y la regla "tests al tocar
cualquier cosa de socios" es no negociable.

## Estado actual: Fase 0 completada

El código corre en local sobre DDEV (PHP 7.4 + MySQL 8 + Composer 2.2 LTS).
Login funciona. Dashboard renderiza. Las pantallas centrales del módulo de
socios cargan sin reventar (con BBDD vacía muestran un modal de "no hay
datos", que es la UX correcta):

- `/gestion/partner/`
- `/gestion/partner/basket/share/`
- `/gestion/weekly/basket/group/`
- `/gestion/booking/`

11 commits desde el snapshot baseline, todos pequeños y trazables. La
historia git es legible y vale la pena revisarla — cada commit tiene en su
mensaje el "por qué".

## Hoja de ruta (orden de prioridad)

1. **Fixtures con Faker** — siguiente paso inmediato. Instalar
   `doctrine/doctrine-fixtures-bundle` + `fakerphp/faker`. Escribir fixtures
   para Partner (con nodos/ciudades), BasketShare (semanal/quincenal),
   EggPeriod, EggAmount, PartnerBasketShare (con start_date/end_date),
   WeeklyBasketGroup. Permite navegar la app con datos realistas en lugar
   de pelear contra BBDD vacía.
2. **Tests funcionales baseline** del módulo de socios — antes de tocar nada
   de ese módulo. "Golden tests" que fijan el comportamiento actual (aunque
   sea defectuoso) para poder refactorizar con red.
3. **Modernización de dependencias muertas**: `friendsofsymfony/user-bundle`
   (→ seguridad nativa de Symfony), `symfony/swiftmailer-bundle` (→
   `symfony/mailer`), `twig/extensions` (→ funciones de Twig core),
   `symfony/web-server-bundle` (→ Symfony CLI),
   `sensio/framework-extra-bundle` (→ atributos PHP 8 nativos),
   `incenteev/composer-parameter-handler` (→ ya no necesario),
   `whiteoctober/breadcrumbs-bundle dev-master` (abandonado).
4. **Symfony 4.4 → 5.4 LTS** (cuando las deps muertas estén fuera).
   Anotaciones → atributos PHP 8.
5. **Symfony 5.4 → 6.4 LTS** (requiere PHP 8.1+).
6. **Auth nuevo + roles**: reescribir con seguridad nativa de Symfony.
   Roles `ROLE_PARTNER`, `ROLE_GESTION`, `ROLE_ADMIN`. Magic-link login
   (sin contraseñas) pensado para la brecha digital del colectivo:
   muchxs socixs nunca han usado software, una contraseña es una
   barrera real.
7. **Acceso para socixs**: panel propio, primero solo lectura
   (calendario de cestas, próximas semanas), luego escritura (saltar
   cesta, cambiar punto de recogida puntualmente).
8. **Importación desde Excel** (sólo al ir a producción): comando
   `app:import-partners-from-xlsx`. Sólo se importa lo de socios; la
   granja ya está en MySQL.
9. **Despliegue**: hosting, CI/CD, copias, monitorización.

Cada fase termina con tests en verde y un tag `vX.Y` en git.

## Decisiones tomadas (no relitigues sin razón fuerte)

- **MySQL 8**, no Postgres. La prod usa MySQL, no compensa migrar.
- **DDEV** para entorno local, no instalación nativa. Reproducible,
  portable, descarta toda una clase de problemas de "en mi máquina sí".
- **PHP 7.4** como punto de partida (mayor versión con la que la
  `composer.lock` de 2021 instala sin pelearse). Subimos progresivamente.
- **Composer 2.2 LTS** porque Flex 1.12.2 no es compatible con Composer
  2.3+ (firma `: void` en `RequireCommand::configure()`). Cuando
  actualicemos Flex a ≥1.18, subimos Composer.
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
- `tests/` — vacío. Aquí vive el trabajo de tests baseline cuando
  arranquemos esa fase.
- `config/packages/` — configuración de bundles. `security.yaml`,
  `doctrine.yaml`, etc.

## Cómo trabajar conmigo (Paco)

- Soy desarrollador Symfony, hablamos sin azúcar.
- Le gusta entender el "por qué" antes de aplicar el "qué".
- No le gusta el over-formatting innecesario, pero sí estructura clara
  cuando es útil.
- Cuando algo se rompe, mando el output completo y rápido. No abrir
  hipótesis sin datos.
