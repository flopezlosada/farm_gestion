# Trasplantar el planificador a otro proyecto Symfony

> **Para quién es esto.** Para quien tenga que montar este planificador en otro
> proyecto (gestión de centro, SGA, o el siguiente). Es un procedimiento, no un
> documento de diseño: aquí está el **qué hacer**. El **por qué** de cada
> decisión está en [`planificador-tareas.md`](planificador-tareas.md), y conviene
> leer al menos su sección 2 (principio rector) antes de empezar, porque hay dos
> reglas que si no se respetan dejan el planificador en un adorno.
>
> Verificado contra el código el 2026-08-26, con el sistema ya funcionando en
> producción y en staging de csa-vega.

## Qué problema resuelve, en dos frases

Un cron de hosting compartido se cae y no avisa: en csa-vega estuvo parado del
20 de julio al 4 de agosto de 2026 y nadie se enteró, porque desde fuera «no
pasó nada» y «no se ejecutó» se ven igual. Esto sustituye ese cron por **una
sola llamada HTTP horaria** («es la hora») decidiendo la aplicación qué toca, y
por un **registro de ejecuciones** que hace visible el silencio.

Si el proyecto destino corre en un hosting con cron fiable y monitorizado, casi
todo esto sobra. Lo que no sobra nunca es el registro de ejecuciones con sus
cuatro estados.

---

## 1. Las dos reglas que no son negociables

Sin estas dos, lo demás es decoración.

**Las tareas van dirigidas por ESTADO, no por el instante del disparo.** La
tarea mira la base de datos y calcula qué falta. Un disparo a las 09:25 en vez
de las 09:00 da el mismo resultado, dos disparos también, y uno perdido lo
recupera el siguiente. Si una tarea del proyecto destino hace algo del tipo
«manda el aviso de hoy» sin comprobar si ya se mandó, hay que reescribirla antes
de traer nada de aquí.

**Todo efecto externo se marca ANTES de producirse, con clave única en la base
de datos.** La unicidad la impone el motor, no un `if` en PHP: los `if` pierden
las carreras. Esto es lo que permite reintentar sin duplicar, y por tanto lo que
permite tener dos relojes a la vez.

---

## 2. Qué se copia y qué se reescribe

**Se copia tal cual, sin tocar una línea** (verificado: ninguno de estos
ficheros menciona nada del proyecto de origen):

```
src/Service/Cron/CronManifest.php          # la interfaz que define el contrato
src/Service/Cron/CronTaskRegistry.php      # lee el manifiesto y decide
src/Service/Cron/CronSchedule.php          # evaluador de cadencias
src/Service/Cron/CronTick.php              # el latido: qué toca y lo lanza
src/Service/Cron/CronRunner.php            # ejecuta una tarea en proceso
src/Service/Cron/CronRunLogger.php         # escribe el registro (por DBAL)
src/Service/Cron/CronRunMode.php           # Preview / AsScheduled / Forced / Resend
src/Service/Cron/CronRunResult.php
src/Service/Cron/EffectLedger.php          # idempotencia de efectos externos
src/Service/Cron/TaskLock.php              # cerrojo de no solapamiento
src/Service/Cron/TeeOutput.php
src/Command/AbstractCronCommand.php        # gate + registro + cerrojo
src/Entity/CronRun.php                     # + su repositorio
src/Entity/EmittedEffect.php               # + su repositorio
src/Controller/CronTickController.php      # el endpoint del reloj
src/Controller/CronHealthController.php    # chequeo de salud 200/503
src/EventListener/CronTickListener.php     # el trabajo, en kernel.terminate
.github/workflows/cron-tick.yml            # el reloj
```

**Se reescribe UNA sola pieza**: `src/Service/Cron/Adapter/AppSettingsCronManifest.php`.
Es la implementación de `CronManifest` para este proyecto. La carpeta `Adapter/`
marca la frontera: **fuera se copia, dentro se reescribe**.

Y se copian también los tests. En particular
`tests/Service/Cron/CronTaskRegistryPortabilityTest.php`, que monta el registro
con un manifiesto **inventado**, sin kernel ni base de datos. Ése es el que
impide que el núcleo se vuelva a acoplar al proyecto: si alguien mete dentro una
dependencia local, falla ahí. No lo borres por «no aporta cobertura».

---

## 3. El contrato: qué tiene que dar el proyecto destino

### 3.1 La implementación del manifiesto

Cuatro métodos:

```php
public function tasks(): array;                       // clave => metadatos (ver 3.2)
public function isEnabled(string $settingKey): bool;   // ¿está encendido ese interruptor?
public function label(string $settingKey): string;     // etiqueta legible, para los mensajes
public function timezone(): string;                    // 'Europe/Madrid'
```

Dos cosas que importan:

- **`isEnabled` recibe tanto claves de tarea como claves de ajuste** (las de
  `requires`). En csa-vega todas viven en la misma tabla de ajustes booleanos;
  en otro proyecto pueden venir de donde sea (fichero, variable de entorno, otra
  tabla).
- **La zona horaria la declara el manifiesto, no el núcleo ni el `php.ini`.**
  Deliberado: el núcleo no debe saber en qué país vive la aplicación.

En csa-vega el adaptador se registra con `#[AsAlias(CronManifest::class)]`, así
que funciona sin tocar `services.yaml`.

### 3.2 La forma de una entrada del manifiesto

```php
'cron.mi_tarea' => [
    'command'          => 'app:mi-comando',   // nombre del comando de consola
    'schedule'         => ['freq' => 'daily', 'hour' => 9],
    'max_delay_hours'  => 36,                 // a partir de aquí, /cron/health da 503
    'requires'         => [self::EMAIL_ENABLED, self::EMAIL_MI_TAREA],
    'depends_on'       => [self::CRON_OTRA_TAREA],
    'needs_recipient'  => false,              // el comando exige --to
    'confirm'          => true,               // la pantalla pide confirmación
    'dry'              => true,               // el comando acepta --dry-run
],
```

Cadencias que entiende el evaluador:

| `freq` | campos | ejemplo |
|---|---|---|
| `daily`  | `hour`, `minute?`  | `['freq' => 'daily', 'hour' => 9]`  |
| `weekly`  | `dow` (1=lunes), `hour`  | `['freq' => 'weekly', 'dow' => 1, 'hour' => 6]`  |
| `monthly`  | `dom` (1-28), `hour`  | `['freq' => 'monthly', 'dom' => 1, 'hour' => 4]`  |
| `interval`  | `minutes`  | `['freq' => 'interval', 'minutes' => 5]`  |

`interval` existe precisamente para gestión de centro, cuyas notificaciones
piden minutos y no horas. **Ojo si se usa**: con tick de cinco minutos el
período del reloj deja de ser irrelevante, el cerrojo de no solapamiento pasa de
conveniente a imprescindible, y **GitHub Actions deja de servir** como reloj (su
`schedule` admite cinco minutos pero se retrasa y descarta pasadas). Para eso,
cron-job.org o Cloudflare Workers.

`dom` no puede pasar de 28: el 29, 30 y 31 no existen todos los meses.

**`max_delay_hours` tiene que ser MAYOR que el período de la cadencia.** Si son
iguales, cualquier retraso normal se lee como caída y la pantalla se llena de
falsas alarmas. En csa-vega: 36 h para las diarias, 192 h para las semanales,
792 h para la mensual.

### 3.3 Los comandos

Cada tarea es un comando de consola que hereda de `AbstractCronCommand`:

```php
protected function doExecute(InputInterface $input, OutputInterface $output): int
{
    // ... el trabajo ...

    return $hizoAlgo
        ? $this->didWork('42 avisos enviados')      // estado "done"
        : $this->nothingToDo('Nadie a quien avisar'); // estado "nothing_to_do"
}
```

`execute()` es `final` en la clase base: ahí viven el gate de interruptores, el
cerrojo y el registro. Las hijas implementan `doExecute()` y **declaran qué
pasó** con `didWork()` o `nothingToDo()`. Un comando que devuelve
`Command::SUCCESS` a secas se registra como `done`, que es mentira si no hizo
nada — y esa mentira es justo la que oculta las caídas.

Para los efectos externos (correos, cobros, ficheros) se usa `emitOnce()`:

```php
$this->emitOnce(
    kind: 'aviso_recogida',
    effect: fn () => $this->mailer->send($mensaje),
    input: $input,                    // para que --resend funcione
    reference: (string) $socio->getId(),
    on: $fechaDelReparto,
    target: $socio->getEmail(),
);
```

Apunta el efecto **antes** de producirlo, con `UNIQUE(kind, reference,
occurred_on)`. Si el apunte choca, ya se hizo y no se repite.

⚠️ **Elegir bien la `reference`.** En csa-vega el primer intento usó la cesta y
resultó que con una cesta extra puntual el mismo día salían dos correos
idénticos: la clave correcta era **socio + fecha**. La pregunta a hacerse es
«¿qué combinación no debe recibir esto dos veces?».

⚠️ **Añadir a `requires` el interruptor general de envío de correo.** Si no, con
el maestro apagado el mailer descarta en silencio, la tarea se registra como
«hizo su trabajo» **y deja los efectos apuntados**: al reencender el correo,
esos avisos ya constan emitidos y no saldrán nunca.

⚠️ **Si el proyecto usa Messenger** (envío asíncrono), este protocolo se rompe:
`send()` vuelve sin error y el apunte queda puesto aunque el envío falle
después. Habría que apuntar en el consumidor, no aquí.

---

## 4. Base de datos

Dos tablas, sin claves ajenas, que nacen vacías:

- `cron_run` — una fila por ejecución (tarea, comando, estado de los cuatro,
  origen del disparo, inicio, fin, código de salida, detalle, salida truncada).
- `emitted_effect` — una fila por efecto externo producido, con
  `UNIQUE(kind, reference, occurred_on)`.

El DDL está en `dev-docs/scheduler/schema-cron-run.sql` y
`schema-emitted-effect.sql`.

⚠️ **`cron_run` tiene que existir ANTES de desplegar el código**, porque la
pantalla de ajustes la consulta al pintar y sin tabla da 500. El orden de
`emitted_effect` es indiferente: sin ella el guardián lo anota en el log y
produce los efectos igual, o sea que las tareas envían como siempre pero sin
protección contra duplicados.

⚠️ **El DDL manual va a un fichero en el repositorio, en el mismo commit que el
código que lo necesita.** No es burocracia: en csa-vega un cambio de esquema
aplicado a mano y no escrito dejó cinco entornos en tres estados distintos, con
producción mes y medio sin una restricción que el código daba por puesta. Lo
descubrió una avería en el siguiente entorno. El ejemplo, con los tres estados
que nos encontramos, está en
`dev-docs/schema/partner-delivery-shift-component-key.sql`.

⚠️ **Un fichero `.sql` que se puede pegar entero tiene que ser seguro pegado
entero, o negarse a correr.** Escribir un guion de pasos con comentarios
«ejecutar sólo si…» acaba importado de un tirón contra producción.

---

## 5. El reloj

El `workflow_dispatch` + `schedule` de `.github/workflows/cron-tick.yml`, tal
cual. Recorre una matriz de entornos y de cada vuelta lee sus propios secretos:

```
CRON_TICK_URL_<ENTORNO>      https://host/cron/tick
CRON_TICK_TOKEN_<ENTORNO>    32 bytes, distinto por entorno
CRON_HEALTH_URL_<ENTORNO>    https://host/cron/health
```

Tres decisiones que conviene no deshacer:

- **Un entorno sin secretos se salta sin fallar.** Así los secretos son el
  interruptor de encendido de cada entorno: se valida en staging, se añade
  producción después, y se apaga staging cuando sobra, sin tocar el fichero.
- **`fail-fast: false`**, o un fallo en staging cancela el tick de producción.
- **El workflow va en el repositorio del proyecto, no en uno aparte.** GitHub
  deshabilita los `schedule` de un repositorio que pasa 60 días sin actividad, y
  un repositorio dedicado sólo al reloj no recibe commits nunca: se apagaría
  solo a los dos meses, que es el mismo fallo silencioso que esto viene a cerrar.

⚠️ **En el servidor la variable NO lleva sufijo de entorno**: el `.env.local` de
cada máquina define `CRON_TICK_TOKEN` a secas. El sufijo es sólo del secreto de
GitHub, porque un único workflow tiene que hablar con varios servidores. Esto
nos costó un diagnóstico entero: la app devolvía 404 y parecía el reloj roto.

**Monta un segundo reloj.** Dos relojes en paralelo al mismo tick son seguros
—para eso está la idempotencia— y gratis. Con uno solo, la fiabilidad depende de
un proveedor. Un servicio tipo cron-job.org o UptimeRobot sobre `/cron/health`
cubre además el caso que ninguna otra pieza cubre: que la web entera esté caída.
En csa-vega esto sigue pendiente y es la deuda más barata de cerrar.

---

## 6. Orden de despliegue

1. Tabla `cron_run` en la base de datos del entorno (**antes** del código).
2. `emitted_effect` cuando sea.
3. Revisar los interruptores de las tareas **antes** de subir el código: las que
   estén encendidas y nadie disparaba hasta ahora **empezarán a ejecutarse**. En
   csa-vega había tres tareas declaradas que ningún cron lanzaba; al encender el
   tick pasaban a correr a diario. Eso es comportamiento nuevo cayendo sobre
   gente real, y es una decisión, no un detalle.
4. El código. ⚠️ Si el despliegue es por FTP con mirror, cuenta con varios
   minutos de 500 mientras se reemplazan ficheros: elige una hora tonta y **no
   toques nada durante la subida** (borrar la caché a mano a mitad hace que la
   aplicación recompile contra un árbol incompleto).
5. El token en el `.env.local` del servidor.
6. Los secretos de ese entorno en GitHub.
7. Lanzar el workflow a mano y comprobar.

**Si ya existe un cron viejo, NO lo quites todavía.** Los dos relojes conviven
sin peligro y el viejo es la red mientras el nuevo se gana la confianza.

---

## 7. Cómo comprobar que funciona de verdad

En este orden, porque cada uno prueba algo que el anterior no:

**El endpoint está cerrado.** Sin cabecera → 404. Con token falso → 404. Con el
bueno → **202 en menos de un segundo** (contesta antes de trabajar).

**Un tick ejecuta lo que toca.** Tras el primer tick debe haber una fila por
tarea que tocara, cada una con su estado real. Si todas salen `done`, sospecha:
lo más probable es que los comandos no estén declarando `nothingToDo()`.

**No duplica.** Dispara dos o tres veces seguidas: **cero filas nuevas** y cero
efectos nuevos. Si aparecen, la tarea no va dirigida por estado.

**Se recupera de un reloj caído.** Retrasa a mano la última ejecución de una
tarea más allá de su `max_delay_hours` → `/cron/health` debe dar **503** con esa
tarea en `late`. Un tick después, la tarea corre sola y el health vuelve a
**200**. Esta es la prueba que justifica todo el diseño; si falla, algo del
evaluador está mal.

**Prueba el camino PESADO, no sólo los avisos.** Un tick que sólo ejecuta tareas
sin trabajo no prueba nada del riesgo real: que php-fpm mate el proceso con un
`request_terminate_timeout` que PHP no puede pisar. Hay que forzar que la tarea
más gorda tenga trabajo de verdad **en un servidor**, no en local. En csa-vega
se hizo deshaciendo a mano el trabajo de la semana en staging y volviendo a
lanzarla: 89 registros materializados, sin morir. De rebote, ese experimento
cazó el drift de esquema del punto 4.

**Comprueba que el aviso LLEGA.** Que el workflow se ponga rojo no sirve si el
correo no sale de GitHub o va a una bandeja que nadie mira. Provoca un fallo (o
espera al primero) y confirma que el correo aparece. Es el eslabón del que
depende todo lo demás y es el más fácil de dar por supuesto.

**Y comprueba que el aviso se ENTIENDE.** Un correo que dice sólo «el workflow
ha fallado» obliga a bucear en un log, y un aviso que cuesta leer se acaba
ignorando — que es exactamente el mecanismo original de la avería. El workflow
escribe la causa y qué mirar en el resumen de la ejecución; conserva eso.

---

## 8. Trampas que ya hemos pagado

- **Los comandos son servicios de un solo ejemplar.** El tick lanza varias
  tareas en el mismo proceso, así que hay que limpiar el estado reportado entre
  pasadas o la segunda hereda el resultado de la primera.
- **`Application::find()` devuelve `LazyCommand`**, no tu clase: Symfony envuelve
  los comandos que declaran `description` en `#[AsCommand]`. Cualquier
  `instanceof` falla **en silencio** si no desenvuelves con `getCommand()`.
- **El cerrojo, con `GET_LOCK` de MySQL y no con una fila en una tabla.** El de
  MySQL se libera al cerrar la conexión; una fila se queda clavada y bloquea la
  tarea para siempre, y sin SSH arreglarlo es entrar a phpMyAdmin. **Prefija el
  nombre con el de la base de datos**: los locks de MySQL son globales al
  servidor y el hosting es compartido. Y ojo, **`GET_LOCK` es reentrante en la
  misma conexión**: para probar que bloquea hace falta una segunda conexión.
- **`CronRunLogger` escribe por DBAL, no por el EntityManager.** Un `flush()`
  arrastraría la unidad de trabajo del comando, y si el comando muere por una
  excepción de Doctrine el EM queda cerrado — justo el caso que más importa
  registrar.
- **`--force` no significa «lo lanzó una persona»**: son dos ejes distintos. Una
  pantalla de diagnóstico puede lanzar como lo haría el reloj (respetando la
  pausa) y seguir siendo manual. Si el origen se deduce de `--force`, la pantalla
  da por vivo un reloj parado.
- **`--dry-run` no pasa por el gate y NO se registra**: una previsualización no
  es una ejecución y no debe falsear la última ejecución de la pantalla.
- **Una opción sólo de CLI no sirve** en un hosting sin consola. Si el rescate de
  un aviso perdido es `--resend`, tiene que haber un botón que lo llame.
- **Si el tick corre en `kernel.terminate`, `set_time_limit(0)`** y confirma que
  el framework llama a `fastcgi_finish_request()` antes. Aun así, PHP no puede
  pisar un `request_terminate_timeout` del pool de fpm: por eso importa que las
  tareas sean reanudables.

---

## 9. Lo que cada proyecto decide de nuevo

No copies estas decisiones, tómalas:

- **El período del tick.** Una hora en csa-vega. Cinco minutos en gestión de
  centro cambia el reloj (GitHub deja de servir) y hace imprescindible el
  cerrojo. Y con tick de cinco minutos un aviso tarda de media dos minutos y
  medio en salir: si el negocio no tolera eso, el cron no es la herramienta y hay
  que disparar en el momento del evento.
- **Dónde viven las cadencias.** En csa-vega, en código y no editables desde la
  web, porque tienen invariantes que no se ven (el congelado del lunes va por
  delante del recordatorio) y quien usa la pantalla no tiene por qué conocerlas.
  Otro proyecto puede querer lo contrario: la interfaz lo permite.
- **Qué se considera un efecto externo** y con qué clave se identifica.
- **Si hace falta el chequeo de salud público sin token.** En csa-vega sí: no
  ejecuta nada, no recibe parámetros y no expone datos personales, y pedir token
  ahí obligaría a configurarlo en el monitor — y un chequeo que cuesta
  configurar acaba sin configurarse.

---

## 10. Lo que se descartó, para no volver a proponerlo

- **Reloj por tráfico de usuarios** (estilo wp-cron): en gestión de centro y SGA
  pueden pasar semanas sin que nadie entre, y los días sin visitas son justo los
  que importan. Un reloj que depende de visitas no es un reloj.
- **Ejecutar el trabajo en el runner de GitHub** (checkout + consola contra la
  base de datos remota): obligaría a abrir MySQL a internet con IPs de runner
  dinámicas. Mucho peor que un endpoint con token.
- **Un paquete Composer compartido entre proyectos**: con dos o tres consumidores
  cuesta más que las copias. Se estandariza el **contrato** (la forma del
  endpoint y del manifiesto) y se copian los ficheros. Cuando sean muchas más
  aplicaciones, se reconsidera.
- **Un banner de aviso en el panel para los usuarios**: lo usan personas sin
  perfil técnico y un aviso así desconcierta. El sistema se arregla solo y avisa
  a quien puede hacer algo.
