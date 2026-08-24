# Planificador de tareas programadas — diseño

> Estado: **pasos 1, 2 y 3 implementados** (paso 1 el 2026-08-05, pasos 2 y 3 el
> 2026-08-24); queda el paso 4, el reloj externo. Fecha del diseño: 2026-08-05.
> Alcance: este proyecto y, con el mismo contrato, **gestión centro** y **SGA**
> (los tres en cdmon, mismo problema).
>
> Este documento vivía en `docs/scheduler/`, que está en `.gitignore` por los
> datos personales que hay en esa carpeta, así que no estaba en el repositorio.
> Movido a `dev-docs/` el 2026-08-24 (ver `dev-docs/README.md`).

## 1. Por qué existe este documento

El 4 de agosto de 2026 se detectó que **ninguna tarea programada de producción se
había ejecutado desde el lunes 20 de julio**. Dos semanas de ceguera total:

- El listado del reparto no se congeló los lunes 27 de julio ni 3 de agosto.
- No salió ni un recordatorio de recogida ni un resumen a administración.
- `var/log/cron.log` no recibió una sola línea en todo ese tiempo, con dos
  tareas programadas a diario.

Nadie se enteró porque **nada en el sistema vigila que las tareas corran**. El
crontab vive en un servidor sin acceso SSH, gestionado por tickets a cdmon, sin
panel, sin visibilidad y sin aviso alguno cuando falla.

Este documento no es "cómo arreglar el cron de cdmon". Es cómo hacer que las
tareas programadas sobrevivan a un reloj poco fiable, en cualquier proyecto.

### Método de diagnóstico (reutilizable)

Sirve para cualquier proyecto con este patrón, y sin necesidad de SSH:

1. **Un log de cron congelado es concluyente.** Todos los comandos escriben
   siempre algo al ejecutarse: un `warning` si el interruptor está apagado
   (desde el paso 1, en un solo sitio: `AbstractCronCommand`, con el texto que
   compone `CronTaskRegistry::inhibitedReason()`), un `note` si no hay trabajo,
   un `success` si actúan. Cero líneas con tareas diarias significa que no
   arrancan, no que arranquen y callen.
2. **Buscar evidencia independiente en los datos.** El congelado del listado es
   responsabilidad exclusiva del cron: las vistas de reparto ya no materializan
   al abrirlas (`GenerateWeeklyDeliveryCommand`, materialización tardía). Por
   tanto:

   ```sql
   SELECT b.id, b.date, COUNT(wb.id) AS cestas
   FROM basket b LEFT JOIN weekly_basket wb ON wb.basket_id = b.id
   WHERE b.date BETWEEN '2026-07-10' AND '2026-08-21'
   GROUP BY b.id, b.date ORDER BY b.date;
   ```

   Resultado el 4 de agosto: 10-jul = 82, 17-jul = 82, 24-jul = 77, **31-jul en
   adelante = 0**. Eso data la última ejecución en el lunes 20 de julio, sin
   tocar el servidor.
3. **Descartar la explicación inocente.** Un parón vacacional cancelado daría
   ceros legítimos: `SELECT * FROM delivery_exception WHERE basket_id BETWEEN ...`.
   Salió vacío.

La misma consulta sirve para detectar que el reloj **vuelve**: el 24 de agosto
mostraba 14-ago = 85, 21-ago = 82 y 28-ago = 91, con las semanas siguientes a
cero. Ese patrón —exactamente una semana congelada por lunes, ni una más— sólo
lo produce el cron, así que cdmon lo revivió entre el 5 y el 10 de agosto sin
avisar. Enterarse tres semanas después, y por una consulta SQL, es justamente lo
que el registro de ejecuciones viene a arreglar.

Y un detalle que ahorra buscar dos averías donde hay una: el recordatorio de
recogida se alimenta **solo** de cestas ya congeladas
(`WeeklyBasketRepository::findPickedByDeliveryDateAndShares`, filtra por
`delivery_date`). Sin congelado no hay destinatarios, así que sale en verde sin
enviar nada aunque los interruptores estén encendidos. El congelado caído
arrastra los correos.

## 2. Principio rector

**La fiabilidad se pone en la aplicación, no en el reloj.**

El reloj es lo que menos controlas: hosting compartido, panel ajeno, servicio
gratuito que se retrasa. Si diseñas suponiendo un reloj puntual y único,
cualquier reloj te rompe. Si diseñas para un reloj impuntual, repetido y a veces
ausente, cualquier reloj sirve.

De ahí salen dos reglas:

**Regla 1 — Las tareas se dirigen por estado, no por el instante del disparo.**
La tarea no hace "lo que toca porque son las nueve": mira la base de datos,
calcula qué falta y lo hace. Un disparo a las 09:25 en lugar de las 09:00 da el
mismo resultado. Dos disparos también. Un disparo perdido lo recupera el
siguiente. Este proyecto ya lo cumple: el recordatorio calcula `hoy + N` y el
congelado coge el próximo Basket pendiente.

**Regla 2 — Todo efecto externo se marca antes de producirse, con clave única en
base de datos.** Correos, SMS, cobros, ficheros. La unicidad la impone el motor,
no un `if` en PHP: los `if` pierden las carreras. Hay precedente en casa — los
`PartnerDeliveryShift` duplicados por doble envío del formulario se cerraron con
una restricción de unicidad, no con una comprobación en código.

## 3. Arquitectura: un tick único, cadencias dentro de la aplicación

El reloj externo dispara **un solo endpoint genérico y sin parámetros** (el
"tick") cada hora. Siempre el mismo. No le dice a la aplicación qué ejecutar.

La aplicación, en cada tick, consulta su manifiesto y su registro de ejecuciones
y decide qué tareas toca lanzar. Es el modelo de Laravel (`schedule:run` cada
minuto, definición de tareas en el código).

Lo que se gana:

- **Al hosting se le pide un cron una vez en la vida.** Nunca más un ticket a
  cdmon para mover un horario o añadir una tarea.
- El chequeo de salud compara contra la cadencia declarada, que ahora es la
  fuente de verdad — no una línea en un fichero de un servidor que no ves.
- La granularidad pasa a ser la del tick. Para estas tareas es irrelevante.

**El período del tick es un parámetro del reloj, no del diseño.** Aquí basta con
una hora; en gestión de centro las notificaciones piden cinco minutos, y la
aplicación no se entera de cada cuánto la llaman. Lo que sí cambia al bajar a
cinco minutos: el manifiesto necesita expresar cadencias por intervalo (hoy sólo
sabe de diario, semanal y mensual), el cerrojo de no solapamiento pasa de
conveniente a imprescindible, y **GitHub Actions deja de servir** — su `schedule`
admite cinco minutos como mínimo pero su propia documentación avisa de retrasos y
de descartes en horas de carga. Para eso, cron-job.org o Cloudflare Workers, que
bajan al minuto (conviene confirmar los límites del plan gratuito antes de
comprometerse).

Y una advertencia de fondo: con tick de cinco minutos un aviso tarda de media dos
minutos y medio en salir. Si el negocio no tolera eso, el cron no es la
herramienta y hay que disparar en el momento del evento.

### La cadencia se edita en el código, no en la web

Decidido el 2026-08-24, después de constatar que el §3 prometía "autoservicio
desde la web" y eso no es lo implementado ni lo que conviene aquí.

El dolor real era depender de cdmon, y eso se cierra con el tick: cambiar una
hora pasa a ser un commit y un despliegue que controlas tú. Ponerlo además en la
web sólo añade que lo pueda cambiar administración, y ahí hay más riesgo que
beneficio: estas cadencias tienen invariantes que no se ven (el congelado del
lunes 06:00 va por delante del recordatorio de las 09:00; con antelación mayor
que 2 Madrid se queda sin aviso), y un desplegable de horas en manos de quien no
las conoce rompe cosas que tardan una semana en notarse.

La pantalla **muestra** la cadencia declarada junto a la última ejecución y el
aviso de retraso, que es lo que la convierte en un panel de salud. Y como el
manifiesto se sirve a través de su propia pieza, cada proyecto puede decidir si
el suyo sale de una constante o de la base de datos sin tocar el núcleo.

### Distinción que hay que respetar

`email.pickup_reminder_days_before` **no configura cuándo se ejecuta** el
recordatorio: configura **a qué reparto apunta** cada ejecución (`hoy + N`). El
cuándo lo fija hoy el crontab. Son dos parámetros distintos que viven en dos
sistemas distintos, y de ahí nace la confusión. Con el tick, los dos viven en la
aplicación y siguen siendo campos separados: cadencia y parámetro de negocio.

## 4. Manifiesto de tareas

Fuente única de verdad, cinco campos por tarea:

| Campo | Para qué |
|---|---|
| nombre | identificador y comando asociado |
| cadencia | cuándo debería correr |
| plazo máximo de retraso | a partir de cuándo se considera caída |
| claves de configuración que la habilitan | gate y explicación en la interfaz |
| tareas de las que depende | validación de coherencia |

De aquí salen el gate, el chequeo de salud, los avisos y la interfaz, sin
duplicar lógica. **Implementado en el paso 1** sobre `AppSettings::CRONS`, que ya
tenía el embrión (mapa clave → comando, más `confirm`/`dry`) y ahora lleva
además cadencia, plazo, `requires`, `depends_on` y `needs_recipient`. Lo lee
`CronTaskRegistry`.

Los interruptores que habilitan una tarea son de DOS naturalezas y conviene no
confundirlas: el interruptor propio de la tarea ("no lo ejecutes por cron"), que
una ejecución manual explícita puede saltar, y los de entrega ("no lo mandes"),
que no se saltan nunca. En el manifiesto son la clave de la entrada y `requires`,
respectivamente.

**Toda tarea que manda correo declara en `requires` el interruptor GENERAL de
envíos** (`email.enabled`), además del suyo. Corregido en el paso 2, porque
faltaba y tenía dos consecuencias: con el general apagado, `KillSwitchMailer`
descarta los mensajes en silencio, la tarea corría entera y se registraba como
"hizo su trabajo" mintiendo en la pantalla; y con la idempotencia del paso 2 en
marcha, esos avisos habrían quedado apuntados como emitidos, así que al reencender
el envío ya constarían y no habrían salido nunca. Un apagado de emergencia de un
día se habría convertido en un grupo de socixs sin aviso para siempre.

## 5. Una ejecución tiene cuatro estados, no dos

| Estado | Significado | ¿Alerta? |
|---|---|---|
| `disabled` | apagada por configuración | No, pero se muestra como apagada |
| `nothing_to_do` | corrió y no había trabajo | No: es sana |
| `done` | corrió e hizo trabajo | No |
| `failed` | falló | Sí |

Hoy todos los comandos terminan con éxito tanto si trabajan como si están
apagados por interruptor, así que desde fuera "apagada" y "hizo su trabajo" son
indistinguibles. El chequeo de salud evalúa el plazo **solo de las tareas
habilitadas**: si no, o te llena de falsas alarmas o te oculta caídas reales.

## 6. Persistencia y exclusión

**Tabla de ejecuciones** `cron_run` (tarea, inicio, fin, estado de los cuatro,
salida truncada). Alimenta el chequeo de salud. Implementada en el paso 1.

**Tabla de efectos emitidos** `emitted_effect`, con índice único sobre (tipo de
efecto, referencia, fecha de negocio). Implementada en el paso 2. Esto es la
idempotencia real: el reintento manda exactamente lo que falta y dos disparos
simultáneos no se pisan porque el segundo choca contra el índice.

> Un sello de "última ejecución" por tarea y día **no basta**: si el envío se cae
> a mitad, con 3 de 40 correos enviados, sellado al principio deja a 37 personas
> sin aviso para siempre, y sellado al final el reintento duplica los 3 primeros.
> El sello sirve para observabilidad, no como cerrojo.

**Cerrojo de no solapamiento**: bloqueo con nombre de MySQL (`GET_LOCK`) por
clave de tarea, tomado en `AbstractCronCommand` antes de trabajar. Corrección
sobre lo que decía este documento, que lo hacía con la tabla de ejecuciones: un
bloqueo de MySQL **se libera solo** al cerrarse la conexión —termine el comando,
lo mate php-fpm por tiempo o muera el proceso de golpe—, mientras que una fila de
"estoy corriendo" se queda clavada y bloquea la tarea para siempre hasta que
alguien entre a borrarla por phpMyAdmin.

El nombre del bloqueo va **prefijado con el nombre de la base de datos**: en
MySQL los bloqueos con nombre son globales al servidor, no a la base de datos, y
en un hosting compartido dos aplicaciones con la misma clave de tarea se
bloquearían entre ellas.

Y son dos mecanismos distintos que hacen falta los dos: el cerrojo evita que dos
procesos trabajen a la vez; la tabla de efectos evita que un reintento
posterior repita lo que ya salió.

**Retención del registro.** `cron_run` y `emitted_effect` crecen sin límite, a
diferencia de `usage_hit`, que sí tiene su purga por minimización. Con siete
tareas son unos pocos miles de filas al año, así que no corre prisa; anotado como
deuda menor para cuando se toque `PurgeUsageHitsCommand` (una sola tarea puede
purgar las tres tablas). `emitted_effect` lleva ya su índice por `emitted_at`
para que esa purga sea barata.

**Auditoría de configuración.** `Setting` guarda solo nombre y valor, sin autor
ni fecha. Así, "no llegan los correos" no se distingue de "alguien apagó el
interruptor el 12 de julio" sin preguntar a la gente. Registrar autor, fecha y
valor anterior en cada cambio. **Pendiente.**

## 7. Seguridad del endpoint

En un hosting sin SSH, **HTTP es la única vía**: el FTP solo deposita ficheros y
el tráfico de usuarios no sirve como reloj (ver descartes). El diseño del tick
reduce la superficie al mínimo posible:

- **Sin parámetros.** El endpoint no recibe qué ejecutar; solo dice "es la hora".
- Token de 32 bytes (256 bits) **en cabecera**, no en la URL, para que no quede
  en logs de acceso ni proxies.
- Comparación con `hash_equals` (no filtrar por tiempos de respuesta).
- Respuesta 404 opaca e idéntica siempre que no cuadre.
- Cerrojo contra ticks repetidos o solapados.

Peor caso con el token robado: ticks de más. No duplican nada (estado más
idempotencia), no leen ni borran datos. Puede costar CPU, que el cerrojo acota.
En proporción, la aplicación ya expone al mundo el login, el formulario de
contacto, el de alta de socixs, el del LAR y todo el blog público.

El token lo genera Paco y vive en `.env.local` del servidor, fuera del repo.

## 8. El reloj

Criterio de elección — y no es la puntualidad: **que avise cuando no ha podido
ejecutar**. Ni el crontab CLI ni el cron-by-URL de cdmon avisan nunca; eso es lo
que costó las dos semanas de ceguera.

Candidatos:

- **GitHub Actions** con `schedule`, en ESTE repositorio (no en uno aparte: ver
  el paso 4). Aquí ya vive el despliegue. Se retrasa entre 5 y 30 minutos; con
  tick horario da igual, con tick de cinco minutos no sirve (ver §3).
- **cron-job.org / EasyCron**: gratis, panel, y avisan por correo cuando la
  llamada falla — reloj y vigilancia en la misma pieza.
- **Cloudflare Workers** con cron triggers.

**Dos relojes en paralelo al mismo tick es seguro y gratis**, precisamente porque
el diseño es idempotente: si uno se retrasa o falla, el otro cubre. La fiabilidad
deja de depender de un proveedor. Plan: GitHub Actions como principal y
cron-job.org como segundo. cdmon queda fuera de la ecuación.

Complemento opcional: un endpoint de salud de **solo lectura** que devuelva 200
si todas las tareas habilitadas están dentro de plazo y 503 si alguna se ha
pasado. No ejecuta nada, no recibe parámetros, no expone datos personales, así
que no necesita token. Cualquier monitor externo lo consulta y avisa. Cubre
además el caso que ninguna otra pieza cubre: que la web esté caída.

## 9. Descartado, con su razón

**Reloj basado en el tráfico de la web** (estilo `wp-cron`): un listener en
`kernel.terminate` que lanza lo pendiente cuando alguien visita. Descartado
porque en gestión centro y SGA pueden pasar **semanas sin que nadie entre**, y
los días sin visitas son justamente los que importan. Un reloj que depende de
visitas no es un reloj.

**Avisar con un banner en el panel**: estas aplicaciones las usan personas sin
experiencia técnica; un aviso así las desconcierta. El sistema debe arreglarse
solo y avisar únicamente a quien mantiene.

**Ejecutar el trabajo en el runner de GitHub** (checkout y `bin/console` contra
el MySQL de cdmon en remoto): obligaría a abrir la base de datos a internet, y
las IPs de los runners son dinámicas, así que ni se podrían restringir. Cambiar
un endpoint con token por un MySQL expuesto es mucho peor.

**Un bundle o paquete compartido entre los tres proyectos**: por ahora no. Se
estandariza el **contrato** (forma del endpoint y del manifiesto) y se copian las
doscientas líneas. Un paquete privado con su versionado y su ciclo de publicación
cuesta más de mantener que tres copias, y extraer un paquete de código que ya ha
sobrevivido en dos o tres sitios sale mejor que diseñarlo en abstracto.
Reevaluar cuando haya que arreglar el mismo fallo dos veces en dos copias, o
cuando aparezca un cuarto proyecto. Lo que sí se cuida desde ya es que el núcleo
no dependa de este proyecto: el acoplamiento vive en dos sitios
(`CronTaskRegistry` lee `AppSettings::CRONS`, y los que escriben lo hacen contra
tablas concretas), y el plan es extraer esas dos costuras a sus propias
interfaces **después del paso 2 y antes del paso 3**, con las piezas ya escritas.

**El cron-by-URL del panel de cdmon**: fuerza el token en la query string, que
queda escrito en los logs de acceso, y tampoco avisa al fallar.

## 10. Qué ya existe y se reutiliza (este proyecto)

- ~~`SettingsController::runCron` duplicado en `SettingsDiagnosticsController::cron`,
  cada uno con su lista blanca~~ → **hecho en el paso 1**: extraído a
  `App\Service\Cron\CronRunner`. La lista blanca ahora es el manifiesto.
- Botones "Ejecutar ahora", "Previsualizar" y —desde el paso 2— "Reenviar" por
  tarea en `/gestion/settings`. Son el puente manual mientras el reloj esté
  caído, y siguen siendo útiles después.
- Falta añadir `fastcgi_finish_request()`: responder 200 al instante y seguir
  trabajando con la conexión cerrada, para no comerse el
  `request_terminate_timeout` de php-fpm. Es del paso 3 (el tick), no del runner
  manual: aquí quien lanza quiere ver la salida.

## 11. Deudas concretas que este diseño cierra

- ~~**Gate duplicado**: los dos `if` de interruptores copiados dentro de cada
  comando~~ → **hecho en el paso 1**, con una corrección sobre lo que decía este
  documento: el gate **no** puede evaluarse en el runner. El cron de producción
  ejecuta `bin/console` y no pasa por la web, y el runner además manda `--force`,
  que salta el gate a propósito; puesto ahí, los interruptores dejarían de
  inhibir el cron real. Vive en `App\Command\AbstractCronCommand`, que cubre los
  tres caminos (consola, web y el futuro tick) y lee el manifiesto. Mismo
  razonamiento para el registro de ejecuciones y para el cerrojo: si sólo los
  aplicara el runner, una caída del cron seguiría siendo invisible.
- ~~**El congelado se protege con un `if`**~~ → **cerrado en el paso 2** con el
  cerrojo. `GenerateWeeklyDeliveryCommand` comprueba si el Basket ya tiene cestas
  materializadas y, si las tiene, no genera. Con un disparo semanal eso nunca
  falló, pero dos procesos pueden atravesar ese `if` a la vez y duplicar el
  reparto entero — y la ventana la abríamos nosotros con el tick horario y los
  dos relojes en paralelo.
- **`pickup_reminder_days_before` mayor que 2 rompe Madrid**: el congelado ocurre
  el lunes a las 06:00 y Madrid recoge el miércoles. Con antelación 2, el aviso
  cae el lunes a las 09:00, tres horas después del congelado, y entra justo. Con
  3 o más, el aviso caería antes de que exista el congelado, y como el
  recordatorio solo lee cestas congeladas, Madrid se queda sin aviso. Hoy eso
  está escrito en el texto de ayuda del campo y nada más: debe ser **validación
  al guardar**, con la dependencia declarada en el manifiesto. Si de verdad se
  quiere más margen, lo correcto es adelantar el congelado, no subir la
  antelación. **Pendiente.**
- **`docs/migracion-prod/crons.txt` no lleva el `-d browscap=`** que cdmon añadió
  el 9 de julio de 2026 y sin el cual el PHP de CLI aborta al arrancar. Si
  alguien regenera el crontab desde esa documentación, nace roto. Actualizar.
  **Pendiente** (y ese fichero tampoco está en el repositorio, por lo mismo que
  no lo estaba este documento: ver la nota del encabezado).
- **Las tareas corren como `root`** en el crontab actual del hosting. La
  aplicación crea caché y logs al ejecutarse; si los crea root, el usuario del
  servidor web deja de poder escribirlos. Pedido a cdmon que las pase al usuario
  de la cuenta. **Pendiente.**

## 12. Plan de implementación

**Paso 1 — Manifiesto y registro de ejecuciones. HECHO (2026-08-05).** Piezas:

| Pieza | Dónde |
|---|---|
| Manifiesto ampliado (cadencia, plazo, `requires`, `depends_on`, `needs_recipient`) | `AppSettings::CRONS` |
| Lectura del manifiesto (gate, cadencia en palabras, retraso, dependencias) | `App\Service\Cron\CronTaskRegistry` |
| Gate + registro, comunes a las siete tareas | `App\Command\AbstractCronCommand` |
| Registro de ejecuciones | `App\Entity\CronRun` + `App\Service\Cron\CronRunLogger` |
| Lanzador manual compartido | `App\Service\Cron\CronRunner` |
| Captura de la salida sin alterar la real | `App\Service\Cron\TeeOutput` |
| DDL a aplicar a mano | `dev-docs/scheduler/schema-cron-run.sql` |

Decisiones que se apartan de lo que decía este documento, con su razón:

- El gate y el registro viven en el comando base, no en el runner (ver §11).
- La salida se captura también en las ejecuciones por consola, envolviendo el
  output en un `TeeOutput`. Motivo: sin SSH, leer `var/log/cron.log` obliga a
  bajarlo por FTP; con la copia en base de datos se ve en la pantalla. Efecto
  colateral asumido: al no ser el tee un `ConsoleOutputInterface`, los mensajes de
  error de estas siete tareas salen por stdout en vez de stderr (el crontab
  redirige `2>&1`, así que en el log queda igual).
- `--dry-run` no pasa por el gate y **no se registra**: una previsualización no
  es una ejecución y no debe falsear la última ejecución de la pantalla.
- Se añadió un sexto campo al manifiesto, `needs_recipient`, porque la lista de
  tareas que exigen `--to` estaba copiada en los dos controladores.

Fuera de alcance deliberado: la cadencia declarada **todavía no manda** — se
muestra y sirve para medir el retraso, pero quien dispara sigue siendo el crontab
de cdmon (paso 3).

**Paso 2 — Idempotencia y exclusión. HECHO (2026-08-24).** Piezas:

| Pieza | Dónde |
|---|---|
| Cerrojo de no solapamiento por tarea (`GET_LOCK`) | `App\Service\Cron\TaskLock` |
| Guardián de idempotencia de efectos | `App\Service\Cron\EffectLedger` |
| Tabla de efectos emitidos | `App\Entity\EmittedEffect` |
| Cerrojo + atajo `emitOnce()` para las hijas | `App\Command\AbstractCronCommand` |
| Modo de reenvío desde la web | `App\Service\Cron\CronRunMode::Resend` |
| DDL a aplicar a mano | `dev-docs/scheduler/schema-emitted-effect.sql` |

Criterio de aceptación, cubierto por test: lanzar el recordatorio dos veces
seguidas no manda ningún correo repetido.

Decisiones del paso 2:

- **El cerrojo es genérico y el guardián también.** Ninguno de los dos sabe qué
  hacen las tareas. El guardián no habla de correos: recibe una clave y algo que
  hacer, y sirve igual para un cobro, un fichero o una llamada a una API que
  factura por operación (la primera versión del diseño lo llamaba "tabla de
  avisos" con un campo "destinatario", y eso lo encerraba en el email).
- **La clave del recordatorio es el socix, no la cesta.** De paso arregla un
  duplicado que existía: quien tiene una cesta extra puntual el mismo día
  aparecía dos veces en la lista y recibía dos correos idénticos.
- **Protocolo en tres tiempos**: se apunta el efecto, se produce, y si falla se
  retira el apunte para que el siguiente intento lo recoja. Límite asumido: si el
  proceso muere entre el apunte y el efecto, ese efecto concreto no se produce y
  no se reintenta. Preferimos perder un efecto rarísimo a duplicarlos; cubrir esa
  ventana exigiría estados intermedios y una política de reintentos.
- **Si no se puede apuntar** (la tabla no existe todavía, por ejemplo), el efecto
  se produce igual y queda una traza en el log: no entregar es peor que arriesgar
  un duplicado, que además exige dos procesos concurrentes.
- **Ojo con el envío asíncrono.** Hoy el correo sale en síncrono (no hay
  Messenger en el proyecto), así que un fallo de SMTP llega como excepción y el
  tercer tiempo funciona. Con una cola por medio, `send()` volvería sin error
  aunque el envío fracasara después y el apunte se quedaría puesto: habría que
  mover la retirada al fallo del consumidor.
- **Reenvío explícito** (`--resend`, y botón "Reenviar" en la pantalla). Sin él,
  rescatar un aviso que no llegó exigiría borrar su apunte a mano por phpMyAdmin.
  Va también en la web a propósito: en producción no hay consola, así que una
  opción sólo de línea de comandos no serviría justo cuando se necesita. El
  runner comprueba que el comando acepte la opción antes de pasarla.

**Paso 3 — Tick y salud. HECHO (2026-08-24).** Piezas:

| Pieza | Dónde |
|---|---|
| Evaluador de cadencias (incluida la de intervalo) | `App\Service\Cron\CronSchedule` |
| Selección y ejecución de lo que toca | `App\Service\Cron\CronTick` |
| Endpoint del tick (token en cabecera, 404 opaco) | `App\Controller\CronTickController` |
| Trabajo tras responder, con la conexión cerrada | `App\EventListener\CronTickListener` |
| Chequeo de salud 200/503, sin token | `App\Controller\CronHealthController` |
| Token del tick (vacío = apagado) | `CRON_TICK_TOKEN` en `.env` |

Decisiones del paso 3:

- **La regla del evaluador no es "¿son las seis de un lunes?"** sino "¿ha corrido
  desde la última vez que le tocaba?". La primera obliga a que el reloj sea
  puntual: si ese tick se pierde, la semana se pierde — exactamente lo de julio.
- **Un fallo se reintenta en el siguiente tick, sin tope.** Un tope por tiempo
  sería inoperante, porque el manifiesto exige que el plazo de retraso sea mayor
  que el período y cualquier ventana llegaría a la ocurrencia siguiente, que
  reactiva la tarea igual. El precio es que una tarea rota reintenta cada tick;
  a cambio se recupera sola en cuanto se arregla la causa.
- **La zona horaria la declara el manifiesto**, no el núcleo ni el `php.ini` del
  hosting. Una hora sin zona está incompleta, y dónde vive la gente de una
  aplicación es dato suyo.
- **Las tareas apagadas ni se miran.** Dejarlas llegar al gate del comando
  escribiría 24 filas "apagada" al día por tarea, y la pantalla dejaría de decir
  nada útil.
- **El trabajo va en `kernel.terminate`**, no en el controlador. Symfony ya llama
  ahí a `fastcgi_finish_request()`, así que se contesta al instante y se trabaja
  con la conexión cerrada.
- **Sin token configurado el endpoint responde 404**, igual que si no existiera:
  un despliegue que olvide la variable deja el planificador apagado, no abierto.
- **El chequeo de salud no lleva token** y es de sólo lectura. Un chequeo que
  cuesta configurar acaba sin configurarse.
- **Se arregló antes un bloqueo que impedía el tick**: tres tareas exigían `--to`
  y no tenían dónde guardar ese email, así que sólo podía venir de la línea del
  crontab. Ahora lo leen de `/gestion/settings` (ver §4).

**Paso 4 — Reloj externo. A medias (2026-08-24).** El workflow de GitHub Actions
está escrito (`.github/workflows/cron-tick.yml`): llama al tick cada hora, falla
si no recibe un 202 y de paso consulta el chequeo de salud. Falta encenderlo, y
eso son tres cosas que no puede hacer nadie más que Paco: generar el token,
ponerlo en el `.env.local` del servidor y crear los tres secretos en GitHub
(`CRON_TICK_URL`, `CRON_TICK_TOKEN`, `CRON_HEALTH_URL`). Y falta el segundo
reloj, un servicio gratuito apuntando al mismo endpoint.

Corrección sobre lo que decía este documento: el workflow va **en este
repositorio**, no en uno aparte. GitHub deshabilita los workflows programados de
un repositorio que pasa 60 días sin actividad, y uno dedicado sólo a hacer de
reloj no recibiría un commit jamás: se apagaría solo a los dos meses, en
silencio, que es exactamente el fallo que estamos cerrando.

Entre el paso 2 y el 3: **extraer las dos costuras de acoplamiento a sus propias
interfaces** (de dónde salen las tareas y sus interruptores, y dónde se apunta lo
que pasa), con las tres piezas ya escritas sobre la mesa.

## 13. Estado operativo

**A 2026-08-24.** El cron de cdmon volvió a funcionar por su cuenta entre el 5 y
el 10 de agosto: las semanas del 14, 21 y 28 de agosto están congeladas con 85,
82 y 91 cestas, y la del 28 se congeló el lunes 24 a las 06:00 (ver §1). Nadie
avisó de que volvía, igual que nadie avisó de que se había caído.

El paso 1 sigue **sin desplegar** (el último despliegue a producción es del 3 de
agosto y el paso 1 se mergeó el 5), así que en producción no hay registro de
ejecuciones todavía. Al desplegar, **la tabla `cron_run` va primero** (ver el
encabezado de su DDL).

**Lo que quedó sin cerrar de la caída:** el listado del 31 de julio (Basket 505)
nunca se congeló. Ese reparto se hizo dibujado al vuelo, así que no afectó a
nadie, pero esa semana no tiene histórico en firme. Rematerializarla requiere
`--basket-id 505`, opción que ningún botón de la web expone. Y el recordatorio de
Madrid del miércoles 5 de agosto no salió.
