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
 */
(function () {
    'use strict';

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
            Array.prototype.forEach.call(select.options, function (opt, i) {
                var li = document.createElement('li');
                li.className = 'csa-dropdown__option';
                li.setAttribute('role', 'option');
                li.textContent = opt.textContent.trim();
                li.setAttribute('aria-selected', i === select.selectedIndex ? 'true' : 'false');
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
            Array.prototype.forEach.call(menu.children, function (li) {
                li.classList.remove('csa-dropdown__option--active');
            });
            if (i >= 0 && menu.children[i]) {
                menu.children[i].classList.add('csa-dropdown__option--active');
                menu.children[i].scrollIntoView({ block: 'nearest' });
                activeIndex = i;
            }
        }

        function moveActive(delta) {
            var n = select.options.length;
            var i = activeIndex < 0 ? select.selectedIndex : activeIndex;
            for (var step = 0; step < n; step++) {
                i = (i + delta + n) % n;
                if (!select.options[i].disabled) {
                    break;
                }
            }
            setActive(i);
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
