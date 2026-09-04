/*
 * csa-collection — añadir y quitar filas de una colección de formulario.
 *
 * Symfony pinta las filas que hay y deja el molde de una nueva en
 * `data-prototype`, con `__name__` donde va el índice. Esto pone el botón de
 * añadir a trabajar —clona el molde con el siguiente índice libre— y el de
 * quitar de cada fila.
 *
 * Uso:
 *
 *     <div data-csa-collection data-prototype="…">
 *         <div data-csa-collection-items>
 *             <div data-csa-collection-item> … <button data-csa-collection-remove hidden> </div>
 *         </div>
 *         <button type="button" data-csa-collection-add hidden>Añadir</button>
 *     </div>
 *
 * Los botones van con `hidden` en el HTML y aquí se enseñan: SIN JAVASCRIPT no
 * funcionarían, y un botón que no hace nada es peor que ninguno. Sin JS se ven
 * las filas que el servidor pintó, que en el alta es una vacía.
 */
(function () {
    'use strict';

    function nextIndex(box) {
        var index = box.querySelectorAll('[data-csa-collection-item]').length;

        // Tras quitar filas en el servidor los índices pueden tener huecos:
        // se busca uno que no esté en uso, o el navegador enviaría dos filas
        // con el mismo nombre y una pisaría a la otra.
        while (box.querySelector('[name*="[' + index + ']"]')) {
            index += 1;
        }

        return index;
    }

    function bindRemove(item) {
        var remove = item.querySelector('[data-csa-collection-remove]');
        if (!remove) {
            return;
        }

        remove.hidden = false;
        remove.addEventListener('click', function () {
            item.remove();
        });
    }

    function init(box) {
        if (box.dataset.csaCollectionReady) {
            return;
        }
        box.dataset.csaCollectionReady = '1';

        var items = box.querySelector('[data-csa-collection-items]') || box;
        var add = box.querySelector('[data-csa-collection-add]');

        Array.prototype.slice
            .call(box.querySelectorAll('[data-csa-collection-item]'))
            .forEach(bindRemove);

        if (!add || !box.dataset.prototype) {
            return;
        }

        add.hidden = false;
        add.addEventListener('click', function () {
            var holder = document.createElement('template');
            holder.innerHTML = box.dataset.prototype.replace(/__name__/g, nextIndex(box)).trim();

            var item = holder.content.firstElementChild;
            items.appendChild(item);
            bindRemove(item);

            var first = item.querySelector('input, select, textarea');
            if (first) {
                first.focus();
            }
        });
    }

    function boot() {
        Array.prototype.slice
            .call(document.querySelectorAll('[data-csa-collection]'))
            .forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
