/*
 * csa-account-menu — cierra el menú de la cuenta al pulsar fuera o con Escape.
 *
 * El menú es un <details> y funciona SIN esto: abre y cierra pulsando el
 * avatar. Lo que añade este script es lo que cualquiera espera de un menú
 * desplegable y que <details> no trae de serie —irse pulsando en otro sitio—,
 * porque un menú que sólo se cierra volviendo a pulsar justo el avatar se queda
 * abierto tapando la pantalla.
 *
 * Opt-in por [data-account-menu], mismo patrón que csa-dropdown y csa-tabs: si
 * este fichero no llega, el menú sigue siendo usable.
 */
(function () {
    'use strict';

    function menus() {
        return document.querySelectorAll('details[data-account-menu]');
    }

    function closeAll(except) {
        Array.prototype.forEach.call(menus(), function (menu) {
            if (menu !== except) {
                menu.open = false;
            }
        });
    }

    function init() {
        if (menus().length === 0) {
            return;
        }

        // En captura y no en burbuja: si un enlace del propio menú navega, da
        // igual; pero un clic en cualquier otro sitio debe cerrarlo aunque ese
        // otro sitio detenga la propagación por su cuenta.
        document.addEventListener('click', function (event) {
            var open = document.querySelector('details[data-account-menu][open]');
            if (!open || open.contains(event.target)) {
                return;
            }
            open.open = false;
        }, true);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll(null);
            }
        });

        // Abrir uno cierra los demás. Hoy sólo hay uno por pantalla, pero el
        // día que haya dos el fallo sería silencioso y raro de ver.
        Array.prototype.forEach.call(menus(), function (menu) {
            menu.addEventListener('toggle', function () {
                if (menu.open) {
                    closeAll(menu);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
