/*
 * csa-table-filter — buscar, filtrar y ordenar una tabla que ya está entera en
 * la página.
 *
 * Para listas de cien o doscientas filas que el servidor pinta de una vez (los
 * recibos de una remesa): ir al servidor por cada filtro sería recargar la
 * ficha entera y perder la pestaña. Las listas largas de verdad (Socixs)
 * siguen filtrando en el servidor con su formulario GET.
 *
 * Activación opt-in: un contenedor con [data-csa-table-filter] que tenga dentro
 * la barra (.prl-toolbar) y UNA tabla. Sin JavaScript la tabla se ve entera y
 * sin orden, que es lo correcto; la barra lleva `hidden` y sólo aparece cuando
 * este script la engancha.
 *
 * Dentro del contenedor:
 *   - input[data-csa-table-search]: busca en el texto de la fila, sin tildes
 *     ni mayúsculas.
 *   - input[type=checkbox][data-csa-table-facet="clave"][value="v"]: la fila
 *     pasa si su atributo data-facet-clave contiene «v» (lista separada por
 *     espacios). Varias casillas de la MISMA clave suman (o una u otra);
 *     claves distintas se exigen todas. Nada marcado en una clave = no filtra.
 *   - [data-csa-table-pop="id"]: botón que abre y cierra el .prl-pop con ese id
 *     (se cierra al pinchar fuera), como los filtros de Socixs.
 *   - th[data-csa-sort="text|num"] con un <a> dentro: ordena por esa columna;
 *     un segundo clic invierte. La celda puede dar su valor en
 *     data-sort-value (un importe sin formato, una fecha Y-m-d); si no, se usa
 *     su texto. El <a> lleva la clase asc/desc, la misma que pinta la flecha
 *     en las cabeceras ordenables del paginador.
 *   - [data-csa-table-count]: dónde escribir cuántas filas se ven.
 */
(function () {
    'use strict';

    /** Minúsculas y sin tildes, para que «garcia» encuentre «García». */
    function plain(text) {
        return (text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
    }

    /** «1.234,50 €» o «-12,20» (texto en formato español) → número; lo que no lo sea, al final. */
    function number(text) {
        var n = parseFloat((text || '').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.'));

        return isNaN(n) ? Number.POSITIVE_INFINITY : n;
    }

    function init(root) {
        if (root.dataset.csaTableFilterReady) {
            return;
        }
        var table = root.querySelector('table');
        var body = table && table.tBodies[0];
        if (!body) {
            return;
        }
        var rows = Array.prototype.slice.call(body.rows);
        var search = root.querySelector('[data-csa-table-search]');
        var facets = Array.prototype.slice.call(root.querySelectorAll('[data-csa-table-facet]'));
        var count = root.querySelector('[data-csa-table-count]');
        var texts = rows.map(function (row) { return plain(row.textContent); });

        function apply() {
            var words = plain(search ? search.value : '').split(/\s+/).filter(Boolean);
            var wanted = {};
            facets.filter(function (box) { return box.checked; }).forEach(function (box) {
                var key = box.getAttribute('data-csa-table-facet');
                (wanted[key] = wanted[key] || []).push(box.value);
            });
            var shown = 0;
            rows.forEach(function (row, i) {
                var ok = words.every(function (w) { return texts[i].indexOf(w) !== -1; })
                    && Object.keys(wanted).every(function (key) {
                        var has = (row.getAttribute('data-facet-' + key) || '').split(/\s+/);
                        return wanted[key].some(function (v) { return has.indexOf(v) !== -1; });
                    });
                row.hidden = !ok;
                shown += ok ? 1 : 0;
            });
            if (count) {
                count.textContent = shown === rows.length ? String(rows.length) : shown + ' de ' + rows.length;
            }
            // El botón del popover se marca si filtra algo, como en Socixs.
            root.querySelectorAll('[data-csa-table-pop]').forEach(function (btn) {
                var pop = document.getElementById(btn.getAttribute('data-csa-table-pop'));
                var n = pop ? pop.querySelectorAll('[data-csa-table-facet]:checked').length : 0;
                btn.classList.toggle('has', n > 0);
                var badge = btn.querySelector('.badge');
                if (badge) {
                    badge.textContent = String(n);
                    badge.hidden = n === 0;
                }
            });
            facets.forEach(function (box) {
                var label = box.closest('label.prl-filt');
                if (label) {
                    label.classList.toggle('has', box.checked);
                }
            });
        }

        function sortBy(th, link) {
            var index = th.cellIndex;
            var numeric = th.getAttribute('data-csa-sort') === 'num';
            var asc = !link.classList.contains('asc');
            root.querySelectorAll('th[data-csa-sort] a').forEach(function (a) { a.classList.remove('asc', 'desc'); });
            link.classList.add(asc ? 'asc' : 'desc');
            var keyOf = function (row) {
                var cell = row.cells[index];
                var raw = cell ? cell.getAttribute('data-sort-value') : null;
                if (raw !== null && raw !== '') {
                    // El valor sin formato ya viene con punto decimal («61.00»).
                    return numeric ? parseFloat(raw) : plain(raw);
                }
                var value = cell ? cell.textContent : '';
                return numeric ? number(value) : plain(value);
            };
            rows.slice().sort(function (a, b) {
                var x = keyOf(a);
                var y = keyOf(b);
                var c = numeric ? x - y : x.localeCompare(y, 'es');
                return asc ? c : -c;
            }).forEach(function (row) { body.appendChild(row); });
        }

        if (search) {
            search.addEventListener('input', apply);
        }
        facets.forEach(function (box) { box.addEventListener('change', apply); });

        root.querySelectorAll('th[data-csa-sort]').forEach(function (th) {
            var link = th.querySelector('a');
            if (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    sortBy(th, link);
                });
            }
        });

        root.querySelectorAll('[data-csa-table-pop]').forEach(function (btn) {
            var pop = document.getElementById(btn.getAttribute('data-csa-table-pop'));
            if (!pop) {
                return;
            }
            btn.addEventListener('click', function (event) {
                event.stopPropagation();
                pop.hidden = !pop.hidden;
            });
            document.addEventListener('click', function (event) {
                if (!pop.hidden && !pop.contains(event.target)) {
                    pop.hidden = true;
                }
            });
        });

        root.querySelectorAll('.prl-toolbar[hidden]').forEach(function (bar) { bar.hidden = false; });
        root.dataset.csaTableFilterReady = '1';
        apply();
    }

    function initAll() {
        document.querySelectorAll('[data-csa-table-filter]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
