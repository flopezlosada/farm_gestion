# dev-docs — SQL de despliegue manual

Aquí vive **una sola cosa**: los ficheros `.sql` que hay que aplicar a mano a
cada base de datos. Nada más. Ni documentos de diseño, ni guías, ni notas de
trabajo.

## Por qué el SQL sí va al repositorio

El proyecto no usa migraciones como fuente de verdad, así que los cambios de
esquema se aplican a mano en cada base. Cuando uno de esos cambios **no queda
escrito**, cada entorno se queda donde le pilló.

Pasó, y salió caro. El DDL de `component_key` se aplicó a mano en julio de 2026
a las tres bases locales y no se escribió en ningún sitio. Un mes después había
**cinco entornos en tres estados distintos**, con producción mes y medio sin una
restricción que el código daba por puesta, y staging directamente incapaz de
congelar el listado del reparto. Lo descubrió una avería.

Regla, entonces: **todo DDL que se aplique a mano va a un fichero aquí, en el
mismo commit que el código que lo necesita.**

Y una regla de forma, que también salió de aquel día: **un `.sql` que se puede
pegar entero tiene que ser seguro pegado entero, o negarse a correr.** Un guion
de pasos con comentarios del tipo «ejecutar sólo si…» acaba importado de un
tirón contra producción.

## Dónde van los documentos de trabajo

En **`docs/`**, que está en `.gitignore` y **fuera del repositorio**. Ahí viven
los diseños, las guías, los listados de reparto, los PDF y ODS de socixs y los
dumps de producción. Decisión de Paco, 2026-08-26: los documentos de trabajo no
entran en el repositorio.

Dos que están ahí y conviene saber que existen, porque el código no los cuenta:

- `docs/scheduler/planificador-tareas.md` — diseño del planificador de tareas
  programadas: el por qué de cada decisión, el método de diagnóstico de un cron
  caído y lo que se descartó con su razón.
- `docs/scheduler/trasplante.md` — procedimiento para montar ese planificador en
  otro proyecto (gestión de centro, SGA): qué ficheros se copian, cuál se
  reescribe y las trampas ya pagadas.

## Contenido

- `schema/` — los DDL manuales. Cada fichero explica en su cabecera qué resuelve
  y en qué orden se aplica.
