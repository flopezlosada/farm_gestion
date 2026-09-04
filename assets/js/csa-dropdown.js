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
 * DOS MODOS, y los decide el tamaño de la lista:
 *
 *  · Pocas opciones → un botón que abre el menú. Es lo de siempre.
 *  · Muchas (más de UMBRAL_BUSCADOR) → el control ES el campo donde se escribe,
 *    y la lista se filtra mientras tecleas.
 *
 * El segundo modo existe porque elegir entre 246 socixs arrastrando un menú es
 * inusable, y en un <select> nativo lo único que hay es saltar a la inicial.
 * Se escribe DENTRO del propio control y no en una caja dentro del menú: la
 * caja heredaba el ancho del control —a menudo estrecho— y quedaba un cajón
 * donde no cabía ni el texto de ayuda.
 *
 * Sincronización bidireccional: si el <select> cambia por código (p.ej. un
 * reset condicional), basta con disparar un evento 'change' nativo sobre él y
 * el dropdown refleja el nuevo valor.
 */
(function () {
    'use strict';

    /* A partir de cuántas opciones el control pasa a ser un campo de búsqueda.
       Doce entran de un vistazo en el menú; más que eso ya obliga a desplazarse. */
    var UMBRAL_BUSCADOR = 12;

    /* Cuántas letras hay que escribir antes de que aparezca la lista. Sin esto,
       enfocar el campo desplegaba los 246 nombres de golpe y tapaba media
       pantalla — y con la lista completa delante, el campo de búsqueda no sirve
       de nada porque no has escrito todavía. Tres letras dejan un puñado de
       resultados, que es lo que se puede leer de un vistazo. */
    var MIN_CARACTERES = 3;

    /* Tope de alto del menú, en píxeles. Tiene que coincidir con el max-height
       de .csa-dropdown__menu: al colocarlo se calcula el hueco disponible, y sin
       este tope un control al final de la página abría hacia arriba un menú de
       mil píxeles que tapaba la ficha entera. */
    var ALTO_MAXIMO = 280;

    /* Compartida con csa-check-filter, que busca sobre los mismos nombres. */
    var fold = require('./csa-fold.js');

    function enhance(select) {
        if (select.dataset.csaEnhanced) {
            return;
        }
        select.dataset.csaEnhanced = '1';

        // El modo se fija al montar: una lista que crece a mitad de página no
        // cambia de control bajo los dedos de quien la está usando.
        var searchable = select.options.length > UMBRAL_BUSCADOR;

        var wrap = document.createElement('div');
        wrap.className = 'csa-dropdown' + (searchable ? ' csa-dropdown--search' : '');

        var trigger;
        var value = null;

        if (searchable) {
            trigger = document.createElement('input');
            trigger.type = 'text';
            trigger.className = 'csa-dropdown__trigger csa-dropdown__trigger--search';
            // Que el navegador no ofrezca aquí direcciones ni nombres guardados:
            // esto filtra una lista, no rellena un dato libre.
            trigger.setAttribute('autocomplete', 'off');
        } else {
            trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'csa-dropdown__trigger';
            value = document.createElement('span');
            value.className = 'csa-dropdown__value';
            trigger.appendChild(value);
        }

        trigger.setAttribute('role', 'combobox');
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        if (select.disabled) {
            trigger.disabled = true;
        }

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

        /* Qué se lee en el campo vacío. La pantalla puede decirlo con
           data-placeholder, porque "— elige —" sirve de etiqueta en un botón
           pero no dice nada en un campo donde hay que escribir: ahí lo útil es
           "Escribe un nombre…". */
        if (searchable) {
            var emptyOption = select.querySelector('option[value=""]');
            trigger.placeholder = select.dataset.placeholder
                || (emptyOption ? emptyOption.textContent.trim().replace(/^[—–-]\s*|\s*[—–-]$/g, '') : '')
                || 'Escribe para buscar…';
        }

        function optionItems() {
            return Array.prototype.slice.call(
                menu.querySelectorAll('.csa-dropdown__option')
            );
        }

        function visibleItems() {
            return optionItems().filter(function (li) {
                return !li.hidden && !li.classList.contains('csa-dropdown__option--disabled');
            });
        }

        function itemFor(i) {
            return menu.querySelector('.csa-dropdown__option[data-index="' + i + '"]');
        }

        function selectedLabel() {
            var opt = select.options[select.selectedIndex];

            return opt && '' !== opt.value ? opt.textContent.trim() : '';
        }

        function syncValueLabel() {
            if (searchable) {
                trigger.value = selectedLabel();

                return;
            }

            var opt = select.options[select.selectedIndex];
            value.textContent = opt ? opt.textContent.trim() : '';
            value.classList.toggle(
                'csa-dropdown__value--placeholder',
                !opt || '' === opt.value
            );
        }

        function buildMenu() {
            menu.innerHTML = '';

            Array.prototype.forEach.call(select.options, function (opt, i) {
                // En modo búsqueda la opción vacía no se ofrece: ya vive en el
                // placeholder, y vaciar la elección se hace borrando el texto.
                if (searchable && '' === opt.value) {
                    return;
                }

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
                    // mousedown y no click: el 'blur' del campo llega antes que
                    // el click y cerraría el menú antes de que se registrara.
                    li.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        commit(i);
                        close();
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
                    return -1 !== haystack.indexOf(term);
                });
                li.hidden = !match;
                if (match) {
                    shown++;
                }
            });

            // Sin resultados se dice: una lista vacía sin explicación parece el
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

            // Si lo marcado se ha ocultado, la marca salta a lo primero que
            // queda: así Enter siempre elige algo que se está viendo.
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
            optionItems().forEach(function (li) {
                li.setAttribute(
                    'aria-selected',
                    Number(li.dataset.index) === select.selectedIndex ? 'true' : 'false'
                );
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
            var vis = visibleItems();

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
            if (!wrap.contains(e.target) && !menu.contains(e.target)) {
                close();
            }
        }

        /**
         * Le da sitio al menú en coordenadas de ventana.
         *
         * Hace falta porque el menú va `position: fixed`, y va fixed porque
         * cualquier ancestro con `overflow: hidden` —una tarjeta con esquinas
         * redondeadas, sin ir más lejos— recortaba el desplegable por el borde.
         *
         * Se abre hacia abajo salvo que no quepa y arriba haya más hueco: en una
         * fila al final de la página, un menú que sale hacia abajo queda fuera
         * de la pantalla y hay que hacer scroll a ciegas.
         */
        function placeMenu() {
            var r = trigger.getBoundingClientRect();
            var margen = 12;
            var debajo = window.innerHeight - r.bottom - margen;
            var encima = r.top - margen;
            var abajo = debajo >= 180 || debajo >= encima;

            menu.style.left = r.left + 'px';
            menu.style.minWidth = r.width + 'px';

            if (abajo) {
                menu.style.top = (r.bottom + 2) + 'px';
                menu.style.bottom = 'auto';
                menu.style.maxHeight = Math.min(ALTO_MAXIMO, Math.max(120, debajo)) + 'px';
            } else {
                menu.style.top = 'auto';
                menu.style.bottom = (window.innerHeight - r.top + 2) + 'px';
                menu.style.maxHeight = Math.min(ALTO_MAXIMO, Math.max(120, encima)) + 'px';
            }
        }

        function open() {
            if (trigger.disabled || !menu.hidden) {
                return;
            }
            buildMenu();
            menu.hidden = false;
            placeMenu();
            trigger.setAttribute('aria-expanded', 'true');
            wrap.classList.add('csa-dropdown--open');
            setActive(select.selectedIndex);
            document.addEventListener('click', onDocClick, true);
            // En coordenadas de ventana, cualquier desplazamiento deja el menú
            // flotando lejos de su control. `true` para capturar también el
            // scroll de contenedores internos, no sólo el de la página.
            window.addEventListener('scroll', placeMenu, true);
            window.addEventListener('resize', placeMenu);
        }

        function close() {
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            wrap.classList.remove('csa-dropdown--open');
            activeIndex = -1;
            document.removeEventListener('click', onDocClick, true);
            window.removeEventListener('scroll', placeMenu, true);
            window.removeEventListener('resize', placeMenu);
        }

        /**
         * El campo vuelve a enseñar lo que hay elegido de verdad. Sin esto
         * quedaría escrito "ana lo" sin nadie seleccionado, y el formulario se
         * enviaría vacío pareciendo relleno.
         *
         * Va aparte de close() y NO dentro: el menú también se cierra al borrar
         * letras por debajo del mínimo, y ahí restaurar el texto borraría lo que
         * se está escribiendo. Sólo se restaura al soltar el campo.
         */
        function restoreLabel() {
            if (searchable) {
                syncValueLabel();
            }
        }

        if (searchable) {
            // Enfocar NO abre la lista. Con 246 nombres, desplegarlos al entrar
            // en el campo tapa media pantalla y estorba justo cuando vienes a
            // escribir. Sólo se selecciona el texto, para que teclear reemplace
            // a quien hubiera elegido.
            trigger.addEventListener('focus', function () {
                trigger.select();
            });

            // La lista aparece cuando hay algo que buscar, y desaparece si se
            // borra por debajo del mínimo: dejarla abierta con dos letras es
            // volver a enseñar media asociación.
            trigger.addEventListener('input', function () {
                if (trigger.value.trim().length < MIN_CARACTERES) {
                    if (!menu.hidden) {
                        close();
                    }

                    return;
                }

                open();
                filterMenu(trigger.value);
            });

            trigger.addEventListener('blur', function () {
                close();
                restoreLabel();
            });
        } else {
            trigger.addEventListener('click', function () {
                menu.hidden ? open() : close();
            });
        }

        trigger.addEventListener('keydown', function (e) {
            switch (e.key) {
                // La flecha abre la lista aunque no se haya escrito nada: es la
                // salida para quien prefiere mirar el listado entero en vez de
                // buscar, y para las listas cortas es el gesto de siempre.
                case 'ArrowDown':
                    e.preventDefault();
                    if (menu.hidden) {
                        open();
                        if (searchable && trigger.value.trim()) {
                            filterMenu(trigger.value);
                        }
                    } else {
                        moveActive(1);
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    menu.hidden ? open() : moveActive(-1);
                    break;
                case 'Enter':
                    // Se come el evento SIEMPRE en modo búsqueda: sin esto, un
                    // Enter dentro de un campo de texto envía el formulario y
                    // anotar a alguien acabaría a medio rellenar.
                    if (searchable || !menu.hidden) {
                        e.preventDefault();
                    }
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
                        restoreLabel();
                    }
                    break;
                case 'Tab':
                    close();
                    restoreLabel();
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
                clearError();
            }
        });

        function clearError() {
            trigger.classList.remove('csa-dropdown__trigger--invalid');
            trigger.removeAttribute('aria-invalid');
        }

        // El <select> real es invisible, así que el globo de "campo obligatorio"
        // del navegador aparece flotando sobre nada. Se corta aquí y se marca el
        // control que SÍ se ve; el texto de lo que falta lo escribe
        // csa-validate.js al final del formulario, para no empujar la fila.
        select.addEventListener('invalid', function (e) {
            e.preventDefault();

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
