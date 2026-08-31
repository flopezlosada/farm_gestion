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

    /*
     * Un enlace a #panel desde FUERA de la barra de pestañas también abre esa
     * pestaña. Sin esto, un "ver los avisos" puesto en cualquier otro punto de
     * la página cambiaría el hash y no pasaría nada visible: el panel destino
     * sigue oculto y el enlace parece roto.
     *
     * Delegado en document y no enganchado enlace a enlace: la página puede
     * pintar esos enlaces dentro de bloques condicionales, y un listener por
     * elemento obligaría a re-inicializar cada vez que cambia el DOM. Sólo actúa
     * si el destino es de verdad un panel de pestaña; cualquier otro ancla sigue
     * comportándose como un ancla normal.
     */
    function followExternalLink(event) {
        var link = event.target.closest ? event.target.closest('a[href^="#"]') : null;
        if (!link || link.classList.contains('csa-tabs__link')) {
            return;
        }

        var id = (link.getAttribute('href') || '').replace('#', '');
        if (!id) {
            return;
        }

        var panel = document.getElementById(id);
        if (!panel || !panel.classList.contains('csa-tabpanel')) {
            return;
        }

        var root = panel.closest('[data-csa-tabs]');
        var trigger = root && root.querySelector('.csa-tabs__link[href="#' + id + '"]');
        if (!trigger) {
            return;
        }

        event.preventDefault();
        trigger.click();
        panel.scrollIntoView({ block: 'nearest' });
    }

    function boot() {
        Array.prototype.slice
            .call(document.querySelectorAll('[data-csa-tabs]'))
            .forEach(init);

        document.addEventListener('click', followExternalLink);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
