# Voluntariado + avisos push — pasos de despliegue

Orden importante. El módulo nace apagado, así que se puede desplegar el código
antes de encender nada.

## 1. Esquema (antes que el código)

Aplicar a mano:

- `dev-docs/schema/volunteering.sql`
- `dev-docs/schema/push-subscription.sql`
- `dev-docs/schema/volunteer-delivery-prep.sql`
- `dev-docs/schema/volunteer-featured.sql`

**Ya aplicados en las tres bases locales** (`db`, `db_prod_snapshot`, `db_test`)
—los dos primeros el 27/08/2026 y los dos últimos el 29/08/2026—, con backup del
golden hecho después. **Quedan staging y producción**, por phpMyAdmin.

Las dos columnas nuevas (`volunteer_category.delivery_prep` y
`volunteer_offer.featured`) las MAPEA el código: si se despliega antes de
aplicarlas, Doctrine las espera y revienta cualquier pantalla de voluntariado,
incluida la home del panel del socix.

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
3. **Recordar el voluntariado a quien se apuntó** (mismo grupo) — el aviso a
   quien ya dijo que sí, poco antes de que le toque. Interruptor aparte porque
   son avisos distintos: uno busca a quien no está, el otro habla con quien ya
   está dentro.

Y antes de que sirva de algo, crear al menos un par de **tipos de trabajo** en
`/gestion/voluntariado/categorias`: sin categorías, nadie puede declarar
preferencias y el primer paso del escalado no encuentra a nadie.

### Quién prepara la cesta (opcional, un paso a mano)

En el panel de cada socix puede salir quién le está montando la cesta esa
semana en su punto de recogida —y el aviso de que no se ha apuntado nadie—,
pero sólo si se dan las dos cosas:

1. Marcar **«Es el montaje del reparto»** en el tipo de trabajo que
   corresponda (Voluntariado › Áreas). Sólo en ése: marcarlo en dos áreas haría
   que el panel señalara como "quien te prepara la cesta" a gente que ese día
   está haciendo otra cosa.
2. Que las tareas de montaje lleven puesto el **punto de recogida** cuyas cestas
   se montan, y caigan en la víspera o el mismo día del reparto.

Sin eso no se pinta nada: ni nombres ni aviso. Es lo que hace que los puntos
donde el montaje todavía no se organiza así —hoy, todos menos Torremocha— no
aparezcan avisando de que nadie se ha apuntado a una tarea que no existe.

## 6. Roles y coordinación por áreas

Dos roles nuevos, con el modelo lectura/escritura del resto del proyecto:

- `ROLE_GESTION_VOLUNTARIADO` — ver las tareas y quién se apunta.
- `ROLE_GESTION_VOLUNTARIADO_EDIT` — publicar, cerrar, pedir gente y gestionar
  los tipos de trabajo. Incluye la lectura por jerarquía.

**Están separados de `ROLE_GESTION_SOCIXS` a propósito.** Quien coordina el
reparto de los viernes necesita saber quién viene ese viernes; darle el rol de
socixs para eso le abriría las fichas, DNIs y domicilios de los 246 socixs. Es
el mismo criterio con el que se separaron las encuestas.

**Para acotar a alguien a un área concreta no se le da ningún rol**: se le
nombra coordinadora de esa categoría en `/gestion/voluntariado/categorias`.
El rol de lectura se deriva solo de ese dato, igual que `ROLE_PARTNER` se deriva
de tener un Partner vinculado, y `VolunteerOfferVoter` limita lo que puede tocar
a las tareas de sus áreas.

Es decir: **abrir un área nueva o cambiar quién la lleva no exige tocar
`security.yaml` ni desplegar.** Es marcar una casilla.

## 7. El rastro de eventos

Todo cambio queda registrado en `volunteer_event`: crear o editar un tipo de
trabajo, cambiar quién lo coordina, publicar o anular una tarea, apuntarse,
darse de baja, anotar a alguien a mano, confirmar asistencia, cerrar la tarea y
cada aviso enviado. Nada se borra al borrar el objeto: las claves foráneas son
`SET NULL`, así que el rastro sobrevive.

El actor se guarda como texto —`gestor:1`, `partner:76`, `system`, `cli`— por lo
mismo: si la cuenta desaparece, la línea sigue diciendo quién fue. Se traduce a
nombre al pintarlo, con el código como respaldo.

Se ve en tres sitios, y **siempre filtrado por área**:

- en la ficha de cada tarea y de cada tipo de trabajo, su propio historial;
- en `/gestion/voluntariado/actividad`, el rastro completo con filtro por tipo;
- en esa misma pantalla, filtrada por persona, entrando desde su fila en «Quién
  hay» — que es el historial de un socix.

Quien coordina un área ve lo de su área y nada más. Los eventos sin área —un
socix cambiando sus preferencias— sólo los ve administración: no son de nadie en
particular y no hay forma honesta de repartirlos.

## 8. ⚠️ El reloj del cron

`cron.volunteer_calls` es la primera tarea del planificador con cadencia por
**intervalo** (cada 60 minutos). Eso sólo vale si el reloj externo dispara
`/cron/tick` con esa frecuencia.

Con el cron del hosting a diario, esta tarea corre **una vez al día** por mucho
que el manifiesto diga 60 minutos, y entonces el segundo paso del escalado
(ampliar el aviso) puede llegar cuando la tarea ya ha pasado.

Si se quiere el comportamiento real, hay que subir la frecuencia del cron de
cdmon que llama al tick.

## 9. Sobre iOS

En iPhone y iPad los avisos push **sólo funcionan si la web está instalada en la
pantalla de inicio** (Compartir → Añadir a inicio). Una pestaña normal no vale, y
Apple no ofrece banner de instalación: hay que explicarlo persona a persona.

La pantalla lo detecta y, en iOS sin instalar, en vez de pedir permiso da la
instrucción. No intenta pedirlo y fallar, porque **un permiso denegado no se
puede volver a pedir**.

## Pantalla de ajustes de avisos: por qué no

gestion-centro tiene una pantalla para elegir cómo recibir cada tipo de aviso
(`/avisos/ajustes`), y aquí **no se ha replicado**. Allí cruza cinco temas
—tareas, guardias, reuniones, agenda, cambios de aula— por tres canales, y una
persona quiere razonablemente las guardias en el móvil y las tareas por correo.

En csa-vega un socix recibe hoy dos cosas: el recordatorio de recogida, por
correo y con un toggle **global** igual para todo el mundo, y los avisos de
voluntariado, por push. Un selector de canal sería un formulario con una sola
opción real.

Lo que sí hacía falta, y está: la casilla **«No quiero que me avisen de
voluntariado»**, porque desmarcar todas las categorías NO silencia (significa
"avísame de lo que sea sencillo") y sin ella la única salida era apagar los
avisos del navegador enteros, perdiendo también los útiles.

Cuando se añada el correo al módulo —o un tercer tipo de aviso— entonces sí toca
esa pantalla, y con el modelo de gestion-centro: sobre todo su
`notificationChannelsSetAt`, que distingue "no ha contestado todavía" de "ha
elegido esto", que son hechos distintos y se comportan distinto.

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
- **Festivos, en las cadencias fijas.** Repetir cada semana, cada dos o una vez
  al mes no consulta el calendario laboral, así que las copias nacen en
  **borrador**: hay que revisarlas antes de publicarlas. El módulo laboral ya
  tiene una entidad `Holiday`, así que cruzarlo es posible el día que moleste de
  verdad.

  Lo que sí está resuelto es el caso que más se repite: cuando la tarea ocurre en
  un punto de recogida se puede elegir **«los días que haya reparto»**, y
  entonces las fechas salen del calendario de ese nodo —el mismo que manda en el
  reparto de cestas—, ya sin las semanas que no abre y con los cierres y
  traslados aplicados.
- **Calendario suscribible (.ics).** Karrot expone las tareas de cada persona
  por una URL con token, para que aparezcan solas en el calendario del móvil.
  Encajaría bien con quien no entra en la web, y no está hecho.
