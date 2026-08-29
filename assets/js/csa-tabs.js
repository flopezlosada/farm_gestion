/*
 * csa-tabs — pestañas para partir una ficha larga en secciones.
 *
 * Activación opt-in: un contenedor con [data-csa-tabs] que envuelve la lista
 * .csa-tabs (los enlaces) y los paneles .csa-tabpanel. Cada enlace apunta con
 * href="#id" al panel que abre, así que SIN JavaScript la página sigue siendo
 * usable: los paneles se ven todos y los enlaces saltan a cada uno. El CSS sólo
 * esconde los inactivos cuando este script marca el contenedor .is-ready.
 *
 * El hash de la URL manda: /gestion/voluntariado/12#avisos abre esa pestaña, y
 * cambiar de pestaña reescribe el hash sin ensuciar el historial, para que
 * recargar o compartir el enlace caiga donde estabas.
 */
(function () {
    'use strict';

    function init(root) {
        if (root.dataset.csaTabsReady) {
            return;
        }

        var links = Array.prototype.slice.call(root.querySelectorAll('.csa-tabs__link'));
        var panels = Array.prototype.slice.call(root.querySelectorAll('.csa-tabpanel'));

        if (!links.length || !panels.length) {
            return;
        }

        function activate(id) {
            var found = false;

            panels.forEach(function (panel) {
                var on = panel.id === id;
                panel.classList.toggle('is-active', on);
                panel.hidden = !on;
                if (on) {
                    found = true;
                }
            });

            links.forEach(function (link) {
                var on = link.getAttribute('href') === '#' + id;
                link.setAttribute('aria-selected', on ? 'true' : 'false');
                var item = link.closest('.csa-tabs__item');
                if (item) {
                    item.classList.toggle('active', on);
                }
            });

            return found;
        }

        links.forEach(function (link) {
            link.addEventListener('click', function (event) {
                var id = (link.getAttribute('href') || '').replace('#', '');
                if (!id) {
                    return;
                }
                event.preventDefault();
                if (activate(id) && window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', '#' + id);
                }
            });
        });

        root.dataset.csaTabsReady = '1';
        root.classList.add('is-ready');

        // El hash sólo manda si nombra un panel de ESTE grupo; si no, la primera.
        var wanted = (window.location.hash || '').replace('#', '');
        if (!wanted || !activate(wanted)) {
            activate(panels[0].id);
        }
    }

    function boot() {
        Array.prototype.slice
            .call(document.querySelectorAll('[data-csa-tabs]'))
            .forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
