# Plan de migración paso a paso

> Léeme **después** de `HANDOFF.md`. Asume que ya entiendes las decisiones de fondo y la estructura del paquete.

Este plan asume el repo `farm_gestion` con:

- **Symfony 4+** (estructura `templates/`, `assets/`, `config/`)
- **Webpack Encore** ya instalado pero `assets/` casi vacío
- **Bootstrap 3 + SB Admin 2 + jQuery + DataTables** servidos vía symlink legacy desde `src/Resources/public/` (frágil, no tocar)
- **Twig** como motor de plantillas
- **Font Awesome** vía kit (`https://kit.fontawesome.com/95aa3a447a.js`)

Si la realidad del repo difiere, **adapta los pasos**, no fuerces el plan.

---

## Fase 0 · Preparación (1 commit)

1. Crear rama `feature/redesign-csa`.
2. Copiar los 3 CSS del paquete a `assets/css/csa/`:

   ```
   assets/css/csa/01-tokens.css
   assets/css/csa/02-base.css
   assets/css/csa/03-components.css
   ```

3. Crear `assets/css/csa/index.css` que importe los 3 en orden:

   ```css
   @import "./01-tokens.css";
   @import "./02-base.css";
   @import "./03-components.css";
   ```

4. En `assets/app.js` (o el entry principal de Encore), añadir al final:

   ```js
   import './css/csa/index.css';
   ```

5. `yarn encore dev` y comprobar que el build pasa.

6. **No tocar** ninguna plantilla todavía. Verificar que la app legacy se sigue viendo idéntica (los nuevos CSS son `csa-*` y no afectan a nada existente).

**Commit**: `chore(redesign): add csa design tokens and base css (no usage yet)`

---

## Fase 1 · Layout shell + macros (1 commit)

Objetivo: tener el chrome nuevo (sidebar + topbar + content) disponible como layout opcional, sin reemplazar `base.html.twig`.

1. Copiar `04-macros.html.twig` a `templates/_macros/csa.html.twig`.
2. Copiar `05-layout-shell.html.twig` a `templates/_layouts/csa_shell.html.twig`.
3. **Adaptar la navegación lateral** del shell a las rutas reales del repo:
   - Abre el `base.html.twig` actual y copia los `path('...')` y labels exactos al sidebar nuevo.
   - Mantén los iconos Font Awesome existentes (`<i class="fa fa-...">`); el CSS `csa-sidebar__icon` los acoge sin cambios.
4. Crear una pantalla "hola mundo" temporal (ej: `templates/dev/preview.html.twig`) que extienda `csa_shell.html.twig` y muestre un `csa.page_header(...)` y un `csa.btn(...)`. Ruta dev-only.
5. Visitar la ruta y validar: sidebar, topbar, breadcrumbs, page header, botón, todo se ve bien.
6. Borrar la pantalla de prueba.

**Commit**: `feat(redesign): add csa shell layout and component macros`

---

## Fase 2 · Pantalla piloto Batch (3 commits, uno por plantilla)

Migrar las 3 plantillas de `templates/Batch/` en este orden:

### 2a. Listado · `Batch/index.html.twig`

1. Hacer backup del archivo original.
2. Reemplazar contenido por `06-pilot-batch-index.html.twig`.
3. Verificar que las variables del controller coinciden:
   - `entities` (lista de Batch)
   - `entity.id`, `entity.batchStatus.id`
   - Rutas: `batch_show`, `batch_edit`, `batch_new`, `batch_analyses`
4. Si DataTables se aplicaba a esta tabla, **desactivarlo** para esta vista. La tabla `csa-table` no necesita DataTables. Si necesitas búsqueda/orden en algún momento, usa Stimulus + filtro client-side ligero.
5. Validar:
   - Lista vacía
   - Lote activo vs finalizado (badges)
   - Click en filas funciona
   - Mobile (360px+)

**Commit**: `feat(redesign): migrate Batch index to new design system`

### 2b. Detalle · `Batch/show.html.twig`

1. Backup del original.
2. Reemplazar por `07-pilot-batch-show.html.twig`.
3. **Importante**: revisar los nombres de campos del Batch entity. El template asume:
   - `entity.purchaseDate`, `entity.receiptDate`, `entity.daysOfLife`, `entity.weight`, `entity.price`, `entity.note`, `entity.batchStatus.id`
   - Si en tu entity son distintos (ej. `getFechaCompra()`, `getCenso()`, etc), **renombrar en el template, no tocar la entity**.
4. Validar:
   - Lote sin notas (sección no aparece)
   - Lote finalizado vs activo (badge correcto)
   - KPIs derivados (huevos, mortalidad) son placeholders — quitar si no hay datos reales aún

**Commit**: `feat(redesign): migrate Batch show to new design system`

### 2c. Formulario · `Batch/edit.html.twig`

1. Backup del original.
2. Reemplazar por `08-pilot-batch-edit.html.twig`.
3. **Crítico**: añadir al inicio:

   ```twig
   {% form_theme edit_form 'form_div_layout.html.twig' %}
   ```

   Esto evita que Bootstrap 3 inyecte clases sucias en los widgets de Symfony Form.
4. Verificar que los campos del form (`edit_form.purchase_date`, `edit_form.note`, etc) coinciden con tu BatchType. Renombrar en el template si difieren.
5. Mantener el `{% block specific_js %}` con los `pickadate` originales — siguen funcionando.
6. Validar:
   - Submit con datos válidos → redirect correcto
   - Submit con errores → errores se muestran
   - Lote finalizado muestra `finalization_date`, activo no
   - Mobile: el form es usable a 360px

**Commit**: `feat(redesign): migrate Batch edit to new design system`

---

## Fase 3 · Resto de módulos

Una vez Batch está estable en producción durante 1–2 semanas sin issues, replicar el mismo patrón al resto. Orden sugerido por valor / frecuencia de uso:

1. **Granja**: Crop, Production, Task, CulturalWork, Compost
2. **Personas**: Member, PickupGroup, Supplier, Customer
3. **Comercio**: Sale, Purchase, Gift
4. **Día a día**: Dashboard (panel principal), Calendar
5. **Comunicación**: Message, Event

Cada módulo es típicamente 1 sprint (3 plantillas + ajustes específicos). El patrón es siempre el mismo:

- index → tabla con `csa-table`
- show → cards `csa-card` + KPIs en aside
- edit/new → `csa-form-section` por bloque temático

---

## Fase 4 · Limpieza (cuando TODO esté migrado)

No hacer hasta tener 100% migrado y al menos 1 mes de estabilidad.

1. Eliminar Bootstrap 3 del symlink legacy.
2. Eliminar AdminLTE / SB Admin 2 skin.
3. Eliminar DataTables si nada lo usa.
4. Promover `csa_shell.html.twig` a layout único, eliminar `base.html.twig` viejo.
5. Opcional: reemplazar Font Awesome por un set local más ligero (Lucide).

---

## Reglas de oro

1. **Cada commit es una sola pantalla.** Reversible, atómico.
2. **No tocar entities, controllers, form types** durante esta migración. Solo plantillas y CSS.
3. **No introducir dependencias JS nuevas.** Si necesitas interactividad, Stimulus (ya está) o vanilla JS.
4. **Form theme mínimo** en cada plantilla con form (`form_div_layout.html.twig`).
5. **Mobile-first**: cada PR se valida en 360px antes de merge.
6. **Si algo se ve raro**, primero abre el HTML estático correspondiente de `09-reference/` en el navegador. Si ahí se ve bien y en Twig no, el problema es tu integración (clase de Bootstrap chocando, JS legacy, etc), no el sistema de diseño.

---

## Riesgos conocidos

| Riesgo | Mitigación |
| --- | --- |
| Bootstrap 3 ensucia widgets de form | `form_theme 'form_div_layout.html.twig'` en cada plantilla con form |
| DataTables aplica su CSS y rompe `csa-table` | No usar DataTables en pantallas migradas. Usar Stimulus si necesitas interactividad |
| Inline styles legacy en algunas plantillas | Buscar y limpiar al migrar cada vista |
| Iconos rotos | Mantener Font Awesome cargado hasta Fase 4 |
| Symlink legacy de `src/Resources/public/` se rompe | **No tocar ese symlink**. Toda CSS nueva va en `assets/` |
