/*
 * csa-check-filter — buscador para listas largas de casillas.
 *
 * Un `EntityType` con `expanded: true, multiple: true` se pinta como una
 * retahíla de pills (ver 03-components.css). Con pocas opciones se lee de un
 * vistazo, que es justo por lo que se eligen las casillas frente a un
 * <select multiple>. Pasada la docena son quince filas de botones y encontrar
 * un nombre concreto es rastrear a ojo.
 *
 * Así que en reposo NO SE PINTA LA LISTA: sólo lo que está marcado, que es la
 * respuesta a la pregunta de la pantalla ("quién coordina esto"), y un campo
 * donde escribir. Las demás pills aparecen al teclear y se van al borrar. Es el
 * mismo criterio que csa-dropdown aplica a los <select> largos —enfocar y ver
 * doscientos nombres de golpe no es elegir, es taparse la pantalla—, pero sin
 * cambiar de control: lo marcado sigue viéndose sin abrir nada.
 *
 * Activación opt-in: sólo dentro de un contenedor con [data-csa-check-filter].
 *
 * LO MARCADO NO SE ESCONDE NUNCA, ni en reposo ni cuando no coincide con lo que
 * se busca. Si desapareciera al teclear, la lista diría "no coordina nadie"
 * mientras la casilla sigue marcada, y quien lo vea desconfía de lo que va a
 * guardar. Se distinguen porque van en verde.
 */
'use strict';

var fold = require('./csa-fold.js');

/* A partir de cuántas casillas se esconde la lista y aparece el buscador. Doce
   son unas cuatro filas de pills y se leen enteras; más que eso ya obliga a
   rastrear. Es el mismo umbral que csa-dropdown usa para su modo de búsqueda. */
var UMBRAL_BUSCADOR = 12;

/* Cuántas letras hay que escribir antes de sacar pills. Con una sola, una "a"
   devuelve casi la lista completa y volvemos al muro que esto viene a quitar.
   Con dos quedan un puñado, que es lo que se lee de un vistazo. */
var MIN_CARACTERES = 2;

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
    search.placeholder = 'Escribe un nombre para buscar…';
    search.setAttribute('aria-label', 'Buscar una persona por su nombre');
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

    list.parentElement.insertBefore(search, list);
    list.parentElement.insertBefore(status, list);

    function apply() {
        var terms = fold(search.value).split(/\s+/).filter(Boolean);
        var buscando = search.value.trim().length >= MIN_CARACTERES;
        var encontradas = 0;
        var marcadas = 0;

        rows.forEach(function (row) {
            var coincide = buscando && terms.every(function (term) {
                return -1 !== row.text.indexOf(term);
            });

            if (coincide) {
                encontradas += 1;
            }

            if (row.checkbox.checked) {
                marcadas += 1;
            }

            row.el.classList.toggle(CLASE_OCULTA, !coincide && !row.checkbox.checked);
        });

        if (buscando) {
            status.textContent = encontradas
                ? encontradas + (1 === encontradas ? ' coincidencia' : ' coincidencias')
                : 'Nadie coincide con «' + search.value.trim() + '».';

            return;
        }

        // En reposo el hueco no se deja vacío: sin esta línea, un área que no
        // tiene a nadie asignado se ve igual que una lista que no ha cargado.
        status.textContent = marcadas
            ? 'Busca a alguien para añadirlo, o pulsa en un nombre para quitarlo.'
            : 'Todavía no coordina nadie esta área.';
    }

    search.addEventListener('input', apply);
    // Marcar o desmarcar mientras hay un filtro puesto cambia qué se sostiene
    // visible por estar marcado, así que hay que repasar la lista.
    container.addEventListener('change', function (event) {
        if ('checkbox' === event.target.type) {
            apply();
        }
    });

    apply();
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
