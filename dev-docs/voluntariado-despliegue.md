# Voluntariado + avisos push — pasos de despliegue

Orden importante. El módulo nace apagado, así que se puede desplegar el código
antes de encender nada.

## 1. Esquema (antes que el código)

Aplicar a mano, a las **tres** bases de trabajo (`db`, `db_prod_snapshot`,
`db_test`) y en producción por phpMyAdmin:

- `dev-docs/schema/volunteering.sql`
- `dev-docs/schema/push-subscription.sql`

Nunca con `doctrine:schema:update --force`: arrastraría el drift preexistente
del resto del esquema y borraría índices que sólo existen a mano.

## 2. Dependencia nueva

`composer install` trae `minishlink/web-push` ^10.1 y cuatro paquetes
transitivos. Ya está en `composer.lock`.

⚠️ Cualquier `composer require/remove` borra `public/bundles/`. El hook
`restore-legacy-assets-symlink` lo recrea tras `install`/`update`, pero si se
ejecuta `assets:install` a mano hay que rehacer el symlink:
`ln -s ../../src/Resources/public public/bundles/app`.

## 3. Assets

Hay JS nuevo (`assets/js/push.js`, requerido desde `app.js`). Hace falta
recompilar Encore:

```
npm install && npm run build
```

Sin esto, el botón de "Activar avisos" no hace nada.

## 4. Claves VAPID

Se generan **una vez** y van sólo al `.env.local` del servidor:

```
php bin/console app:push-generate-vapid-keys
```

Pegar `VAPID_PUBLIC_KEY` y `VAPID_PRIVATE_KEY` en `.env.local`. Revisar que
`VAPID_SUBJECT` apunta a una dirección real nuestra.

**Si estas claves cambian más adelante, todos los navegadores suscritos dejan de
recibir avisos y hay que volver a pedirles permiso uno a uno** — cosa que no va
a hacer nadie. Se generan una vez y no se tocan.

Con las claves vacías, el envío es un no-op silencioso. Es el estado normal en
local y en los tests.

## 5. Encender el módulo

En `/gestion/settings`, grupo «Funcionalidades en rodaje»:

1. **Voluntariado** — abre el módulo (menú, pantallas, bloque del panel).
2. **Avisar de tareas de voluntariado sin cubrir** (grupo «Tareas programadas»)
   — enciende el escalado automático de avisos.

Y antes de que sirva de algo, crear al menos un par de **tipos de trabajo** en
`/gestion/voluntariado/categorias/listado`: sin categorías, nadie puede declarar
preferencias y el primer paso del escalado no encuentra a nadie.

## 6. ⚠️ El reloj del cron

`cron.volunteer_calls` es la primera tarea del planificador con cadencia por
**intervalo** (cada 60 minutos). Eso sólo vale si el reloj externo dispara
`/cron/tick` con esa frecuencia.

Con el cron del hosting a diario, esta tarea corre **una vez al día** por mucho
que el manifiesto diga 60 minutos, y entonces el segundo paso del escalado
(ampliar el aviso) puede llegar cuando la tarea ya ha pasado.

Si se quiere el comportamiento real, hay que subir la frecuencia del cron de
cdmon que llama al tick.

## 7. Sobre iOS

En iPhone y iPad los avisos push **sólo funcionan si la web está instalada en la
pantalla de inicio** (Compartir → Añadir a inicio). Una pestaña normal no vale, y
Apple no ofrece banner de instalación: hay que explicarlo persona a persona.

La pantalla lo detecta y, en iOS sin instalar, en vez de pedir permiso da la
instrucción. No intenta pedirlo y fallar, porque **un permiso denegado no se
puede volver a pedir**.

## Lo que NO incluye esta entrega

- **Correo.** Los avisos van sólo por push. Quien no tiene cuenta de acceso no
  recibe nada; su canal es el panel. Añadir correo es un bloque aparte con su
  propio toggle.
- **Cuota de horas por socix.** No hay denominador acordado, así que el panel
  enseña la mediana de quienes participan en vez de un objetivo. El modelo de
  `GuardiaQuota` de gestion-centro (cuota tecleada, cero = exento, atada a un
  curso) es la referencia si algún día la asamblea acuerda una.
- **Agregado por familia.** Se apunta un socix concreto; los acompañantes van
  como número. Agregar por unidad familiar se puede hacer luego leyendo
  `parent_id` de `Partner`.
