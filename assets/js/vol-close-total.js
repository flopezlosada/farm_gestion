/*
 * vol-close-total — cuántas horas se van a imputar al cerrar una tarea de
 * voluntariado, recalculadas mientras se marca la lista.
 *
 * Cerrar una tarea suma horas a la aportación de socixs reales y no se deshace
 * solo: quien lo hace tiene que ver el total ANTES de pulsar, no enterarse por
 * el mensaje de después. Ése es todo el trabajo de este script.
 *
 * Activación opt-in: el <form> del cierre lleva [data-vol-total] con los minutos
 * que la tarea computa por defecto. Dentro, cada fila aporta una casilla
 * [data-vol-attended] y un campo [data-vol-minutes]; el resultado se escribe en
 * [data-vol-total-output].
 *
 * SIN JAVASCRIPT NO SE ROMPE NADA: el servidor ya pinta ahí el total que hay
 * imputado ahora mismo, que es cierto, y el cálculo de verdad —el que cuenta—
 * lo hace VolunteeringController::close(). Esto es sólo el reflejo en vivo.
 *
 * Los acompañantes NO multiplican, igual que en el servidor: las horas cuelgan
 * de un socix con ficha, y quien viene con su criatura no ha trabajado el doble.
 */
(function () {
    'use strict';

    /* Media hora es "0,5 h" en castellano. Sin decimal cuando es redondo: "2 h"
       se lee mejor que "2,0 h" y es la misma regla que la macro csa.qty. */
    function formatHours(minutes) {
        var hours = minutes / 60;
        var text = hours % 1 === 0 ? String(hours) : hours.toFixed(1);

        return text.replace('.', ',') + ' h';
    }

    function init(form) {
        var output = form.querySelector('[data-vol-total-output]');
        if (!output) {
            return;
        }

        var fallback = parseInt(form.dataset.volTotal, 10) || 0;

        function recalculate() {
            var total = 0;

            Array.prototype.slice
                .call(form.querySelectorAll('[data-vol-attended]'))
                .forEach(function (box) {
                    if (!box.checked) {
                        return;
                    }

                    var row = box.closest('tr');
                    var field = row && row.querySelector('[data-vol-minutes]');
                    // En blanco significa "los de la tarea", igual que en el
                    // servidor. Un cero escrito a mano sí es cero: por eso se
                    // mira si hay texto y no si el número es falsy.
                    var raw = field ? field.value.trim() : '';
                    total += raw === '' ? fallback : Math.max(0, parseInt(raw, 10) || 0);
                });

            output.textContent = formatHours(total);
        }

        form.addEventListener('change', recalculate);
        form.addEventListener('input', recalculate);
        recalculate();
    }

    function boot() {
        Array.prototype.slice
            .call(document.querySelectorAll('form[data-vol-total]'))
            .forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
