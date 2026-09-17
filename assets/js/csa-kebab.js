/*
 * csa-kebab — menús de acciones hechos con <details class="prt-kebab">.
 *
 * El <details> ya abre y cierra solo, sin JavaScript, y por eso se eligió: un
 * menú que funciona aunque el script no cargue. Lo que NO sabe hacer un
 * <details> es comportarse como un menú entre varios: abrir el segundo dejaba
 * el primero abierto, y pinchar en cualquier otro sitio no cerraba ninguno. En
 * una tabla con un menú por fila acababan tres desplegados a la vez.
 *
 * Aquí se añade sólo eso: al abrir uno se cierran los demás, y un clic fuera o
 * la tecla Escape cierran el que esté abierto. Nada de posicionar ni pintar,
 * que es cosa del CSS.
 *
 * La exclusión la da además el atributo `name` de <details> en el HTML, que
 * los navegadores recientes respetan sin script; esto la cubre en los que no.
 */
(function () {
    'use strict';

    function closeAll(except) {
        document.querySelectorAll('details.prt-kebab[open]').forEach(function (menu) {
            if (menu !== except) {
                menu.removeAttribute('open');
            }
        });
    }

    // `toggle` no burbujea: se escucha en fase de captura para verlo desde el
    // documento sin tener que engancharse a cada menú, también a los que se
    // pinten después.
    document.addEventListener('toggle', function (event) {
        var menu = event.target;
        if (menu instanceof HTMLDetailsElement && menu.classList.contains('prt-kebab') && menu.open) {
            closeAll(menu);
        }
    }, true);

    document.addEventListener('click', function (event) {
        if (!(event.target instanceof Element) || !event.target.closest('details.prt-kebab')) {
            closeAll(null);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll(null);
        }
    });
})();
