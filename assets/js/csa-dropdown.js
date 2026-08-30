/*
 * csa-dropdown — "enhancer" de <select> nativos.
 *
 * Reemplaza visualmente cualquier <select> por un dropdown con la estética
 * csa (el menú nativo del navegador/SO no se puede estilar). El <select> real
 * se mantiene en el DOM, oculto pero funcional: el form de Symfony sigue
 * posteando igual y el JS condicional de la pantalla sigue escuchando 'change'.
 *
 * Activación opt-in: sólo se aplica a los <select> dentro de un contenedor con
 * el atributo [data-csa-dropdowns], para no afectar a pantallas no preparadas.
 *
 * Sincronización bidireccional: si el <select> cambia por código (p.ej. un
 * reset condicional), basta con disparar un evento 'change' nativo sobre él y
 * el dropdown refleja el nuevo valor.
 *
 * BUSCADOR AUTOMÁTICO en las listas largas. Elegir entre 246 socixs arrastrando
 * un menú es inusable, y en un <select> nativo lo único que hay es saltar a la
 * inicial. A partir de UMBRAL_BUSCADOR opciones aparece un campo de filtro; por
 * debajo no, porque sobre tres opciones un buscador es una caja vacía que
 * estorba. Sin configurar nada: el componente lo decide por el tamaño de la
 * lista.
 */
(function () {
    'use strict';

    /* A partir de cuántas opciones aparece el buscador. Doce entran de un
       vistazo en el menú; más que eso ya obliga a desplazarse. */
    var UMBRAL_BUSCADOR = 12;

    /* Sin acentos y en minúsculas, para que "agustin" encuentre a "Agustín" y
       "jose" a "JOSÉ" — en la base de datos muchos nombres están en mayúsculas.
       El rango ̀-ͯ son las marcas diacríticas que deja NFD; se usa en
       vez de \p{Diacritic} porque no exige soporte de propiedades Unicode. */
    function fold(text) {
        return text
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase();
    }

    function enhance(select) {
        if (select.dataset.csaEnhanced) {
            return;
        }
        select.dataset.csaEnhanced = '1';

        var wrap = document.createElement('div');
        wrap.className = 'csa-dropdown';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'csa-dropdown__trigger';
        trigger.setAttribute('role', 'combobox');
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        if (select.disabled) {
            trigger.disabled = true;
        }

        var value = document.createElement('span');
        value.className = 'csa-dropdown__value';
        trigger.appendChild(value);

        var menu = document.createElement('ul');
        menu.className = 'csa-dropdown__menu';
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(trigger);
        wrap.appendChild(menu);
        select.classList.add('csa-dropdown__native');

        var activeIndex = -1;

        // El buscador sólo existe si la lista lo pide. Se crea una vez y se
        // reutiliza: recrearlo en cada apertura perdería el foco a medias.
        var searchable = select.options.length > UMBRAL_BUSCADOR;
        var searchRow = null;
        var search = null;

        if (searchable) {
            searchRow = document.createElement('li');
            searchRow.className = 'csa-dropdown__searchrow';
            // role=presentation: es una fila del <ul> pero NO una opción, y sin
            // esto un lector de pantalla la anunciaría como una más de la lista.
            searchRow.setAttribute('role', 'presentation');

            search = document.createElement('input');
            search.type = 'text';
            search.className = 'csa-dropdown__search';
            search.placeholder = 'Escribe para buscar…';
            search.setAttribute('aria-label', 'Buscar en la lista');
            // Que el navegador no ofrezca aquí direcciones ni nombres guardados:
            // esto filtra una lista, no rellena un dato.
            search.setAttribute('autocomplete', 'off');
            searchRow.appendChild(search);
        }

        function optionItems() {
            return Array.prototype.slice.call(
                menu.querySelectorAll('.csa-dropdown__option')
            );
        }

        function visibleItems() {
            return optionItems().filter(function (li) {
                return !li.hidden;
            });
        }

        function itemFor(i) {
            return menu.querySelector('.csa-dropdown__option[data-index="' + i + '"]');
        }

        function syncValueLabel() {
            var opt = select.options[select.selectedIndex];
            value.textContent = opt ? opt.textContent.trim() : '';
            value.classList.toggle(
                'csa-dropdown__value--placeholder',
                !opt || opt.value === ''
            );
        }

        function buildMenu() {
            menu.innerHTML = '';

            if (searchRow) {
                menu.appendChild(searchRow);
            }

            Array.prototype.forEach.call(select.options, function (opt, i) {
                var li = document.createElement('li');
                li.className = 'csa-dropdown__option';
                li.setAttribute('role', 'option');
                li.textContent = opt.textContent.trim();
                li.setAttribute('aria-selected', i === select.selectedIndex ? 'true' : 'false');
                // El índice viaja en el DOM porque al filtrar la posición del
                // <li> deja de coincidir con la del <option>, y confundirlas
                // seleccionaría a otra persona.
                li.dataset.index = i;
                if (opt.disabled) {
                    li.classList.add('csa-dropdown__option--disabled');
                } else {
                    li.addEventListener('click', function () {
                        commit(i);
                        close();
                        trigger.focus();
                    });
                }
                menu.appendChild(li);
            });
        }

        /**
         * Deja visibles sólo las opciones que casan con lo escrito. Busca por
         * TROZOS sueltos: "ana lo" encuentra "Ana Lozano" igual que "lozano ana",
         * porque nadie recuerda si la lista pone antes el nombre o el apellido.
         */
        function filterMenu(query) {
            var terms = fold(query).split(/\s+/).filter(Boolean);
            var shown = 0;

            optionItems().forEach(function (li) {
                var haystack = fold(li.textContent);
                var match = terms.every(function (term) {
                    return haystack.indexOf(term) !== -1;
                });
                li.hidden = !match;
                if (match) {
                    shown++;
                }
            });

            // Sin resultados se dice; una lista vacía sin explicación parece el
            // componente roto y no "no hay nadie que se llame así".
            var empty = menu.querySelector('.csa-dropdown__empty');
            if (0 === shown && !empty) {
                empty = document.createElement('li');
                empty.className = 'csa-dropdown__empty';
                empty.setAttribute('role', 'presentation');
                empty.textContent = 'No hay nadie que encaje';
                menu.appendChild(empty);
            } else if (shown > 0 && empty) {
                empty.remove();
            }

            // Si lo que estaba marcado se ha ocultado, la marca salta a lo
            // primero que queda: así Enter siempre elige algo que se ve.
            var current = itemFor(activeIndex);
            if (!current || current.hidden) {
                var vis = visibleItems();
                setActive(vis.length ? Number(vis[0].dataset.index) : -1);
            }
        }

        function commit(i) {
            select.selectedIndex = i;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncValueLabel();
            markSelected();
        }

        function markSelected() {
            Array.prototype.forEach.call(menu.children, function (li, i) {
                li.setAttribute('aria-selected', i === select.selectedIndex ? 'true' : 'false');
            });
        }

        function setActive(i) {
            optionItems().forEach(function (li) {
                li.classList.remove('csa-dropdown__option--active');
            });

            activeIndex = i;

            var li = i >= 0 ? itemFor(i) : null;
            if (li && !li.hidden) {
                li.classList.add('csa-dropdown__option--active');
                li.scrollIntoView({ block: 'nearest' });
            }
        }

        /**
         * Mueve la marca por las opciones QUE SE VEN. Recorrer select.options
         * saltaría a nombres filtrados: la flecha parecería no hacer nada
         * durante veinte pulsaciones y luego elegiría a alguien que no está en
         * pantalla.
         */
        function moveActive(delta) {
            var vis = visibleItems().filter(function (li) {
                return !li.classList.contains('csa-dropdown__option--disabled');
            });

            if (!vis.length) {
                return;
            }

            var at = -1;
            for (var k = 0; k < vis.length; k++) {
                if (Number(vis[k].dataset.index) === activeIndex) {
                    at = k;
                    break;
                }
            }

            var next = at < 0
                ? (delta > 0 ? 0 : vis.length - 1)
                : (at + delta + vis.length) % vis.length;

            setActive(Number(vis[next].dataset.index));
        }

        function onDocClick(e) {
            if (!wrap.contains(e.target)) {
                close();
            }
        }

        function open() {
            if (trigger.disabled) {
                return;
            }
            buildMenu();
            menu.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            wrap.classList.add('csa-dropdown--open');
            setActive(select.selectedIndex);
            document.addEventListener('click', onDocClick, true);

            // El foco al buscador y no al menú: quien abre una lista de
            // doscientos nombres viene a escribir, no a bajar con la flecha.
            if (search) {
                search.value = '';
                filterMenu('');
                search.focus();
            }
        }

        function close() {
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            wrap.classList.remove('csa-dropdown--open');
            activeIndex = -1;
            document.removeEventListener('click', onDocClick, true);
        }

        trigger.addEventListener('click', function () {
            menu.hidden ? open() : close();
        });

        if (search) {
            search.addEventListener('input', function () {
                filterMenu(search.value);
            });

            // El teclado del buscador es el mismo que el del trigger: escribir,
            // bajar y Enter tiene que ser un gesto seguido, sin tener que salir
            // del campo para elegir.
            search.addEventListener('keydown', function (e) {
                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        moveActive(1);
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        moveActive(-1);
                        break;
                    case 'Enter':
                        // Siempre, haya marca o no: sin esto, Enter dentro de un
                        // campo de texto ENVÍA el formulario, y anotar a alguien
                        // acabaría a medio rellenar.
                        e.preventDefault();
                        if (activeIndex >= 0) {
                            commit(activeIndex);
                            close();
                            trigger.focus();
                        }
                        break;
                    case 'Escape':
                        e.preventDefault();
                        close();
                        trigger.focus();
                        break;
                }
            });

            // Un clic en el campo no debe cerrar el menú por el listener de
            // documento ni reabrirlo por el del trigger.
            search.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }

        trigger.addEventListener('keydown', function (e) {
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    menu.hidden ? open() : moveActive(1);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    menu.hidden ? open() : moveActive(-1);
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    if (menu.hidden) {
                        open();
                    } else if (activeIndex >= 0) {
                        commit(activeIndex);
                        close();
                    }
                    break;
                case 'Escape':
                    if (!menu.hidden) {
                        e.preventDefault();
                        close();
                    }
                    break;
                case 'Tab':
                    close();
                    break;
            }
        });

        // El <select> cambió por código (reset condicional, etc.): reflejarlo.
        select.addEventListener('change', function () {
            if (menu.hidden) {
                syncValueLabel();
                markSelected();
            }
            if (select.checkValidity()) {
                trigger.classList.remove('csa-dropdown__trigger--invalid');
                trigger.removeAttribute('aria-invalid');
            }
        });

        // El <select> real es invisible, así que el aviso de "campo obligatorio"
        // del navegador no se ve y el formulario parece no responder al enviarlo.
        // Se marca el control que SÍ se ve y se le lleva el foco — al primero de
        // los inválidos, que es donde el navegador habría llevado al usuario.
        select.addEventListener('invalid', function () {
            var first = !document.querySelector('.csa-dropdown__trigger--invalid');
            trigger.classList.add('csa-dropdown__trigger--invalid');
            trigger.setAttribute('aria-invalid', 'true');
            if (first) {
                trigger.focus();
            }
        });

        syncValueLabel();
    }

    function init(root) {
        (root || document)
            .querySelectorAll('[data-csa-dropdowns] select')
            .forEach(enhance);
    }

    // El script puede ejecutarse después de DOMContentLoaded (Encore lo carga
    // al final): en ese caso el evento ya pasó y hay que inicializar ya.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init();
        });
    } else {
        init();
    }

    // Por si una pantalla inyecta selects más tarde.
    window.csaEnhanceDropdowns = init;
})();
