# dev-docs — documentación técnica versionada

Aquí viven los documentos de diseño y los SQL de despliegue manual que **sí**
tienen que estar en el repositorio: explican el "por qué" de decisiones que el
código no cuenta, y sin ellos un cambio de máquina se lleva por delante el
criterio con el que se tomaron.

No confundir con `/docs/`, que está en `.gitignore` a propósito: ahí viven los
listados de reparto, los PDF y ODS de socixs y los dumps de producción, o sea
**datos personales reales**, que no pueden entrar nunca en el repositorio.

Regla para esta carpeta: **sólo texto técnico**. Ni nombres de socixs, ni
correos, ni teléfonos, ni volcados de datos. Si un documento necesita un
ejemplo, se inventa.

## Contenido

- `scheduler/` — el planificador propio de tareas programadas: diseño completo,
  método de diagnóstico y los DDL que hay que aplicar a mano en cada base de
  datos (el proyecto no usa migraciones como fuente de verdad).
