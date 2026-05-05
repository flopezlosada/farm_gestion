# CSA Vega de Jarama — herramienta de gestión

Software de gestión interna para la asociación CSA Vega de Jarama: socixs, modalidades de cesta, calendario de reparto semanal, gallinas y huevos, huerta y producción.

Este repositorio parte de un snapshot de marzo de 2021 (proyecto interno `gestion_csa_4` en Symfony 4.4) y se está modernizando paso a paso, con tests, mejor autenticación y acceso para socixs.

## Estado actual

- **Snapshot importado**: marzo 2021. Symfony 4.4, PHP 7.4, MySQL 5.7. Webpack Encore para assets.
- **Funciona en producción**: la parte de granja (huerta, gallinas, lotes, cosechas, registro de huevos…).
- **Nunca ha funcionado en producción**: la parte de socixs (Partners, BasketShares, WeeklyBasket, etc.). Existen entidades y controladores, pero no se ha usado nunca con usuarios reales. Asumimos que tiene bugs latentes y se cubrirá con tests antes de tocarla.
- **Datos de socixs**: hoy en un Excel separado. Se importarán al pasar a producción mediante un comando ad-hoc.
- **Auth y roles**: hechos con FOSUserBundle (abandonado). Se reescribirá con la seguridad nativa de Symfony, añadiendo roles para socix, gestión y admin, y login por enlace mágico (sin contraseñas) pensado para la brecha digital del colectivo.

## Levantar el entorno local

Requiere [Docker Desktop](https://www.docker.com/products/docker-desktop/) y [DDEV](https://ddev.readthedocs.io/en/stable/users/install/) instalados en la máquina.

```bash
# Primera vez:
ddev start                  # arranca PHP 7.4 + MySQL 8 + nginx
ddev composer install       # ya lo hace post-start, pero por si acaso
ddev yarn install
ddev exec bin/console doctrine:database:create
ddev exec bin/console doctrine:migrations:migrate
ddev exec yarn dev          # compila assets

# Día a día:
ddev start
ddev launch                 # abre la app en el navegador
```

Mailpit (capturador local de emails): http://localhost:8025

Base de datos:

```bash
ddev mysql                  # cliente mysql interactivo
ddev describe               # credenciales y URLs
```

## Estructura del repo

```
src/Controller/      Controladores Symfony (53)
src/Entity/          Entidades Doctrine (74)
src/Repository/      Repositorios Doctrine
src/Form/            Formularios Symfony
src/Migrations/      Migraciones de esquema (incompletas, en progreso)
src/Tests/           Tests obsoletos del snapshot
templates/           Plantillas Twig (325)
config/              Configuración de Symfony
public/uploads/      Archivos subidos por usuarios (FUERA de git)
```

## Hoja de ruta

1. **Fase cero** (en curso): poner el código a correr en local con DDEV. Composer al día, app boot-able, primera pantalla en el navegador.
2. **Tests baseline**: golden tests funcionales sobre los flujos de la parte de socixs (que nunca se ha probado en prod), antes de refactorizar nada.
3. **Modernización dependencias**: sustituir piezas muertas (FOSUserBundle, Swiftmailer, twig/extensions, web-server-bundle, sensio/framework-extra-bundle).
4. **Auth y roles**: reescribir autenticación con seguridad nativa de Symfony. Roles `ROLE_PARTNER`, `ROLE_GESTION`, `ROLE_ADMIN`. Magic link login.
5. **Symfony 4.4 → 5.4 LTS**: una vez deps muertas fuera, salto a 5.4. Anotaciones → atributos PHP 8.
6. **Symfony 5.4 → 6.4 LTS**: requiere PHP 8.1+.
7. **Acceso para socixs**: panel propio, primero solo lectura (calendario de cestas, próximas semanas), luego escritura (saltar cesta, cambiar punto de recogida puntualmente).
8. **Importación desde Excel**: comando `app:import-partners-from-xlsx` para alimentar producción al desplegar.
9. **Despliegue**: hosting, CI/CD, copias de seguridad, monitorización.

Cada fase termina con tests y un tag `vX.Y` en el repo.

## Convenciones

- Commits en español, formato [Conventional Commits](https://www.conventionalcommits.org/es/v1.0.0/) (`feat:`, `fix:`, `chore:`, `test:`, `docs:`).
- Branch principal: `main`. Trabajo en ramas `feat/*`, `fix/*`, `chore/*`. PR-like a `main` (aunque seamos una persona, mantenerlo da disciplina y revisabilidad).
- Tests sobre código que se modifica: obligatorios.
- Documentar decisiones técnicas en `docs/decisions/` con plantilla ADR breve.
