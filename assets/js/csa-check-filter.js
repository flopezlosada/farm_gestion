/*
 * csa-check-filter — buscador para listas largas de casillas.
 *
 * Un `EntityType` con `expanded: true, multiple: true` se pinta como una
 * retahíla de pills (ver 03-components.css). Con pocas opciones se lee de un
 * vistazo, que es justo por lo que se eligen las casillas frente a un
 * <select multiple>. Pasada la cuarentena de opciones son quince filas y
 * encontrar un nombre concreto es rastrear a ojo.
 *
 * Esto le pone un campo de búsqueda encima y esconde las pills que no
 * coinciden. Se filtra lo YA pintado, en vez de cambiar a un desplegable con
 * búsqueda como hace csa-dropdown, porque lo que aporta la casilla es ver de un
 * golpe quién está marcado, y un desplegable esconde precisamente eso.
 *
 * Activación opt-in: sólo dentro de un contenedor con [data-csa-check-filter].
 *
 * LO MARCADO NO SE ESCONDE NUNCA, aunque no coincida con lo que se busca. Si al
 * teclear desapareciera, la lista diría "no coordina nadie" mientras la casilla
 * sigue marcada, y quien lo vea desconfía de lo que va a guardar. Se distinguen
 * porque las marcadas van en verde.
 */
'use strict';

var fold = require('./csa-fold.js');

/* A partir de cuántas casillas aparece el buscador. Doce son unas cuatro filas
   de pills y se leen enteras; más que eso ya obliga a rastrear. Es el mismo
   umbral que csa-dropdown usa para su modo de búsqueda. */
var UMBRAL_BUSCADOR = 12;

/* Clase de ocultación propia en lugar del atributo `hidden`: la pill es el
   <label>, y su `display: inline-flex` gana por especificidad al
   `display: none` que el navegador aplica a [hidden]. La regla de esta clase
   lleva !important en el CSS por lo mismo. */
var CLASE_OCULTA = 'csa-check-filter__hidden';

/**
 * La unidad que se esconde para una casilla: el <div> que Symfony pone por
 * opción. Si un layout futuro no lo envolviera —el div llevaría entonces varias
 * casillas— cae al <label>, que es la pill visible en cualquier caso.
 */
function rowFor(checkbox) {
    var parent = checkbox.parentElement;

    if (parent && 1 === parent.querySelectorAll('input[type="checkbox"]').length) {
        return parent;
    }

    return checkbox.labels && checkbox.labels[0] ? checkbox.labels[0] : checkbox;
}

function enhance(container) {
    if (container.dataset.csaCheckFilterReady) {
        return;
    }

    var checkboxes = Array.prototype.slice.call(
        container.querySelectorAll('input[type="checkbox"][name$="[]"]')
    );

    if (checkboxes.length <= UMBRAL_BUSCADOR) {
        return;
    }

    container.dataset.csaCheckFilterReady = '1';

    var rows = checkboxes.map(function (checkbox) {
        return {
            el: rowFor(checkbox),
            checkbox: checkbox,
            // El texto se calcula UNA vez: normalizar cuarenta nombres en cada
            // pulsación es trabajo repetido, y la etiqueta no cambia.
            text: fold(checkbox.labels && checkbox.labels[0] ? checkbox.labels[0].textContent : '')
        };
    });

    // El campo va antes de las pills y dentro del field, para que quede bajo su
    // etiqueta y no por encima. El primer row nos da el contenedor real de las
    // opciones sin tener que suponer cuántos divs lo envuelven.
    var list = rows[0].el.parentElement;

    var search = document.createElement('input');
    search.type = 'search';
    search.className = 'csa-input csa-check-filter';
    search.placeholder = 'Buscar por nombre…';
    search.setAttribute('aria-label', 'Buscar en la lista');
    // Sin esto, Enter en el buscador envía el formulario: se guardaría el alta
    // a medias creyendo que sólo se estaba buscando.
    search.addEventListener('keydown', function (event) {
        if ('Enter' === event.key) {
            event.preventDefault();
        }
    });

    var status = document.createElement('p');
    status.className = 'csa-check-filter__status';
    // Lo lee el lector de pantalla al cambiar: sin esto, quien no ve las pills
    // teclea sin saber si queda algo.
    status.setAttribute('aria-live', 'polite');
    status.hidden = true;

    list.parentElement.insertBefore(search, list);
    list.parentElement.insertBefore(status, list);

    function apply() {
        var terms = fold(search.value).split(/\s+/).filter(Boolean);
        var shown = 0;

        rows.forEach(function (row) {
            var matches = terms.every(function (term) {
                return -1 !== row.text.indexOf(term);
            });
            var visible = matches || row.checkbox.checked;

            row.el.classList.toggle(CLASE_OCULTA, !visible);

            if (matches) {
                shown += 1;
            }
        });

        if (!terms.length) {
            status.hidden = true;

            return;
        }

        status.hidden = false;
        status.textContent = shown
            ? shown + (1 === shown ? ' coincidencia' : ' coincidencias')
            : 'Nadie coincide con «' + search.value.trim() + '».';
    }

    search.addEventListener('input', apply);
    // Marcar o desmarcar mientras hay un filtro puesto cambia qué se sostiene
    // visible por estar marcado, así que hay que repasar la lista.
    container.addEventListener('change', function (event) {
        if ('checkbox' === event.target.type) {
            apply();
        }
    });
}

function init(root) {
    Array.prototype.forEach.call(
        (root || document).querySelectorAll('[data-csa-check-filter]'),
        enhance
    );
}

if ('loading' === document.readyState) {
    document.addEventListener('DOMContentLoaded', function () {
        init(document);
    });
} else {
    init(document);
}
