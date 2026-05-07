# Handoff de rediseño · CSA Vega de Jarama

> **Para la sesión de Claude Code conectada al repo `farm_gestion`.**
> Este documento es tu punto de entrada. Léelo entero antes de tocar nada.

## Qué es este paquete

Un rediseño visual completo del back-office de gestión, hecho aparte como mockup
en HTML/React. Aquí tienes los **tokens, los componentes y las pantallas de
referencia** para llevarlo al repo Symfony de forma incremental, sin romper la app.

El mockup vivo (no incluido aquí) tenía 4 pantallas: panel principal, listado de
lotes, detalle de lote y formulario de edición de lote. Las pantallas se
reproducen en este paquete como **HTML+CSS plano** (sin React) para que sea
trivial portarlas a Twig.

## Decisiones de fondo (no relitigues sin razón fuerte)

1. **Migración aditiva, no big-bang.** Convive con SB Admin 2 / Bootstrap 3
   actual. Ningún cambio rompe pantallas existentes. Se introduce una nueva capa
   CSS (prefijada `csa-`) y se va migrando pantalla a pantalla.

2. **No tocar `src/Resources/public/`.** Esa es la capa legacy con el
   symlink frágil documentado en `CLAUDE.md`. Toda la nueva estética vive en
   `assets/` (Webpack Encore), que hoy está prácticamente vacío.

3. **Empezar por una pantalla piloto: el listado de lotes (`templates/Batch/`).**
   Es de granja (parte que SÍ se usa a diario), tiene listado + detalle + form,
   y el dominio es razonablemente acotado. Si funciona bien aquí, replicamos
   patrón al resto.

4. **No introducir nuevas dependencias JS pesadas.** Se mantiene jQuery,
   Bootstrap 3 y DataTables porque están en uso. Los componentes nuevos son
   CSS + macros Twig. Si hace falta interacción nueva, vanilla JS o Stimulus
   (que ya tienes vía `webpack-encore-bundle`).

5. **Tipografías por @import de Google Fonts** en `app.css`. La paleta usa
   colores OKLCH con fallback hex para máxima compatibilidad (Bootstrap 3 lo
   soporta sin problema en navegadores modernos).

6. **No se modifica el modelo de datos ni controllers.** Solo plantillas Twig
   y CSS. La superficie de cambio es muy contenida.

## Archivos en este paquete

```
handoff/
├── HANDOFF.md                       ← este archivo
├── 01-tokens.css                    ← variables CSS (paleta, tipografía, espaciado, sombras…)
├── 02-base.css                      ← reset suave + estilos de body, headings, link colors
├── 03-components.css                ← botones, inputs, cards, tablas, badges, sidebar nuevo
├── 04-macros.html.twig              ← macros Twig para componentes (botón, card, badge, kpi…)
├── 05-layout-shell.html.twig        ← nuevo layout (sidebar + topbar) que extiende base
├── 06-pilot-batch-index.html.twig   ← listado de lotes rediseñado
├── 07-pilot-batch-show.html.twig    ← detalle de lote rediseñado
├── 08-pilot-batch-edit.html.twig    ← formulario de lote rediseñado
├── 09-reference/
│   ├── panel.html                   ← HTML+CSS plano del panel principal
│   ├── batch-index.html             ← HTML+CSS plano del listado
│   ├── batch-show.html              ← HTML+CSS plano del detalle
│   └── batch-edit.html              ← HTML+CSS plano del formulario
└── 10-migration-plan.md             ← plan paso a paso, qué hacer y en qué orden
```

## Cómo usarlo

1. Lee este archivo (HANDOFF.md) y `10-migration-plan.md`.
2. Mira las 4 pantallas de `09-reference/` en un navegador para entender el destino visual.
3. Aplica los pasos del plan de migración. Cada paso es un commit pequeño,
   reversible, con su test correspondiente cuando toque.
4. Si algo no encaja con la realidad del repo (rutas, nombres de variables,
   convenciones), **adáptalo** — los archivos de este paquete son orientativos,
   no canónicos. El stack real es la fuente de verdad.

## Lo que NO hace este paquete

- **No reemplaza Bootstrap 3.** Convive con él. Si en el futuro hay presupuesto
  para subir Bootstrap a 5 o tirar Bootstrap entero, las clases `csa-*` están
  pensadas para sobrevivir esa migración (no dependen de utilidades BS3).
- **No rediseña el blog público** (`templates/Blog/*` + `base_blog.html.twig`).
  Este handoff es solo para el back-office (`/gestion/...`).
- **No toca auth, roles ni magic-link** (sub-fases 8.5–8.7 de la hoja de ruta).
  Esas son funcionales, no visuales.
- **No incluye iconografía nueva.** Sigues con Font Awesome del kit
  (`https://kit.fontawesome.com/95aa3a447a.js`). El sistema visual está pensado
  para usar muy pocos iconos y los que use ya están disponibles en FA.
