/*
 * Arrastrar un turno de voluntariado a otro día del calendario.
 *
 * Opt-in por [data-vcal]. Los turnos que se pueden mover llevan
 * [data-vcal-draggable] y la URL a la que enviar el movimiento; los días que
 * admiten soltar llevan [data-vcal-drop] y su fecha en data-vcal-day.
 *
 * El servidor sigue siendo la fuente de verdad, igual que en el calendario de
 * recogida: se envía el POST, se recibe la página del calendario ya movido, y
 * se reemplaza sólo la rejilla (.vcal) y los avisos flash. Sin JavaScript los
 * turnos son enlaces normales y mover se hace desde la ficha del turno.
 *
 * Todo va delegado en el documento: la rejilla se reemplaza entera tras cada
 * movimiento y así no hay nada que reenganchar.
 */
(function () {
    'use strict';

    let dragging = null;

    function root() {
        return document.querySelector('[data-vcal]');
    }

    document.addEventListener('dragstart', function (e) {
        const chip = e.target.closest('[data-vcal-draggable]');
        if (!chip) return;
        dragging = chip;
        chip.classList.add('vcal-chip--dragging');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox no arranca el arrastre sin algún dato.
        e.dataTransfer.setData('text/plain', chip.dataset.vcalMoveUrl);
    });

    document.addEventListener('dragend', function (e) {
        const chip = e.target.closest('[data-vcal-draggable]');
        if (chip) chip.classList.remove('vcal-chip--dragging');
        dragging = null;
        clearOver();
    });

    document.addEventListener('dragover', function (e) {
        if (!dragging) return;
        const cell = e.target.closest('[data-vcal-drop]');
        if (!cell) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (!cell.classList.contains('vcal-over')) {
            clearOver();
            cell.classList.add('vcal-over');
        }
    });

    document.addEventListener('dragleave', function (e) {
        const cell = e.target.closest('[data-vcal-drop]');
        if (cell && !cell.contains(e.relatedTarget)) cell.classList.remove('vcal-over');
    });

    document.addEventListener('drop', function (e) {
        const cell = e.target.closest('[data-vcal-drop]');
        if (!cell || !dragging) return;
        e.preventDefault();

        const chip = dragging;
        dragging = null;
        clearOver();

        // Soltado en su propio día: nada que mover.
        if (chip.closest('[data-vcal-day]') === cell) return;

        send(chip.dataset.vcalMoveUrl, cell.dataset.vcalDay);
    });

    function clearOver() {
        document.querySelectorAll('.vcal-over').forEach(function (el) { el.classList.remove('vcal-over'); });
    }

    async function send(url, date) {
        const cal = root();
        if (!cal || !url) return;

        const fields = {
            fecha: date,
            _csrf_token: cal.dataset.vcalCsrf,
            tipo: cal.dataset.vcalTipo || '',
            tarea: cal.dataset.vcalTarea || ''
        };

        cal.classList.add('vcal-busy');
        try {
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(fields)
            });
            const doc = new DOMParser().parseFromString(await resp.text(), 'text/html');
            const fresh = doc.querySelector('[data-vcal]');
            if (!fresh) { window.location.reload(); return; }
            cal.replaceWith(document.importNode(fresh, true));

            // Los avisos flash del shell (movido / no se pudo): se refrescan en sitio.
            const content = document.querySelector('.csa-content');
            if (content) {
                const old = content.querySelector('.csa-alerts');
                if (old) old.remove();
                const alerts = doc.querySelector('.csa-alerts');
                if (alerts) content.insertBefore(document.importNode(alerts, true), content.firstElementChild);
            }
        } catch (err) {
            window.location.reload();
        }
    }
})();
