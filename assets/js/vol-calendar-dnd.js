/*
 * Arrastrar un turno de voluntariado a otro día del calendario (gestión).
 *
 * Opt-in por [data-vcal]. Los turnos que se pueden mover llevan
 * [data-vcal-draggable], la URL a la que enviar el movimiento y su hora; los
 * días que admiten soltar llevan [data-vcal-drop] y su fecha en data-vcal-day.
 *
 * Mientras se arrastra, el turno de origen queda como fantasma y el día
 * destino se ilumina con un indicador «Mover aquí · HH:MM», hijo de la propia
 * celda: su sitio lo da la rejilla, no una medición.
 *
 * El servidor sigue siendo la fuente de verdad, igual que en el calendario de
 * recogida: se envía el POST, se recibe la página del calendario ya movido, y
 * se reemplaza sólo la rejilla ([data-vcal]) y los avisos flash. Sin
 * JavaScript los turnos son enlaces normales y mover se hace desde la ficha.
 *
 * Todo va delegado en el documento: la rejilla se reemplaza entera tras cada
 * movimiento y así no hay nada que reenganchar.
 */
(function () {
    'use strict';

    let dragging = null;
    let hint = null;

    function root() {
        return document.querySelector('[data-vcal]');
    }

    function clearOver() {
        document.querySelectorAll('.scal__cell--over').forEach(function (el) { el.classList.remove('scal__cell--over'); });
        if (hint) { hint.remove(); hint = null; }
    }

    document.addEventListener('dragstart', function (e) {
        const chip = e.target.closest('[data-vcal-draggable]');
        if (!chip) return;
        dragging = chip;
        chip.classList.add('scal-chip--ghost');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox no arranca el arrastre sin algún dato.
        e.dataTransfer.setData('text/plain', chip.dataset.vcalMoveUrl);
    });

    document.addEventListener('dragend', function (e) {
        const chip = e.target.closest('[data-vcal-draggable]');
        if (chip) chip.classList.remove('scal-chip--ghost');
        dragging = null;
        clearOver();
    });

    document.addEventListener('dragover', function (e) {
        if (!dragging) return;
        const cell = e.target.closest('[data-vcal-drop]');
        if (!cell) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (cell.classList.contains('scal__cell--over')) return;

        clearOver();
        cell.classList.add('scal__cell--over');
        if (cell !== dragging.closest('[data-vcal-day]')) {
            hint = document.createElement('div');
            hint.className = 'scal-drop-hint';
            hint.textContent = 'Mover aquí · ' + (dragging.dataset.vcalTime || '');
            const items = cell.querySelector('.scal__items') || cell;
            items.insertBefore(hint, items.firstChild);
        }
    });

    document.addEventListener('dragleave', function (e) {
        const cell = e.target.closest('[data-vcal-drop]');
        if (cell && !cell.contains(e.relatedTarget)) {
            cell.classList.remove('scal__cell--over');
            if (hint && cell.contains(hint)) { hint.remove(); hint = null; }
        }
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

            // La barra de atención y la leyenda también cambian al mover.
            ['.scal-attention', '.scal-legend'].forEach(function (sel) {
                const old = document.querySelector(sel);
                const neu = doc.querySelector(sel);
                if (old && neu) old.replaceWith(document.importNode(neu, true));
                else if (old && !neu) old.remove();
            });

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
