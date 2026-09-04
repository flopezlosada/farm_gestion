/*
 * El calendario de turnos en modo socix: contar lo marcado y abrir el panel
 * «Me apunto».
 *
 * Opt-in por [data-scal-socix]. Cada ficha lleva en data-scal-* lo que el panel
 * enseña (día, hora, sitio, plazas, lo que cuenta) y si se puede apuntar o ya
 * va. Pulsar la ficha abre el panel; pulsar la casilla sólo marca.
 *
 * La barra de abajo dice cuántos turnos hay marcados y cuáles, con los nombres
 * derivados de lo marcado —agrupando por tarea y enumerando días— y el botón
 * con la frase completa en singular o plural. Sin JavaScript la casilla y el
 * botón siguen funcionando; esto sólo lo hace legible.
 */
(function () {
    'use strict';

    const root = document.querySelector('[data-scal-socix]');
    if (!root) return;

    const bar = root.querySelector('[data-scal-bar]');
    const dialog = root.querySelector('[data-scal-dialog]');
    const monthName = root.dataset.scalMonthName || '';

    function checked() {
        return Array.from(root.querySelectorAll('.scal-chip__check:checked'));
    }

    // «Montaje de cestas, 17 y 24 de septiembre · Desbroce, 5 de septiembre».
    function summary(boxes) {
        const byTask = {};
        boxes.forEach(function (box) {
            const chip = box.closest('[data-scal-turno]');
            if (!chip) return;
            (byTask[chip.dataset.scalTitle] = byTask[chip.dataset.scalTitle] || []).push(Number(chip.dataset.scalDay));
        });
        return Object.keys(byTask).map(function (task) {
            const days = byTask[task].sort(function (a, b) { return a - b; }).map(String);
            const list = days.length > 1 ? days.slice(0, -1).join(', ') + ' y ' + days[days.length - 1] : days[0];
            return task + ', ' + list + ' de ' + monthName;
        }).join(' · ');
    }

    function refresh() {
        if (!bar) return;
        const boxes = checked();
        const n = boxes.length;
        bar.querySelector('[data-scal-count]').textContent = n;
        bar.querySelector('[data-scal-label]').textContent =
            n === 0 ? 'Marca los días a los que puedas venir' : n === 1 ? 'Tienes 1 turno marcado' : 'Tienes ' + n + ' turnos marcados';
        bar.querySelector('[data-scal-summary]').textContent = n ? ' · ' + summary(boxes) : '';
        const go = bar.querySelector('[data-scal-submit]');
        go.disabled = n === 0;
        go.textContent = n === 0 ? 'Marca al menos un turno' : n === 1 ? 'Apuntarme al turno marcado' : 'Apuntarme a los ' + n + ' turnos marcados';
        const clear = bar.querySelector('[data-scal-clear]');
        if (clear) clear.hidden = n === 0;
    }

    root.addEventListener('change', function (e) {
        if (e.target.matches('.scal-chip__check')) refresh();
    });

    const clear = root.querySelector('[data-scal-clear]');
    if (clear) {
        clear.addEventListener('click', function () {
            checked().forEach(function (box) { box.checked = false; });
            refresh();
        });
    }

    // ---- el panel «Me apunto» ----
    function fill(chip) {
        const d = chip.dataset;
        const set = function (key, value) {
            const el = dialog.querySelector('[data-f="' + key + '"]');
            if (el) el.textContent = value || '';
        };
        set('area', d.scalArea);
        set('title', d.scalTitle);
        set('date', d.scalDate);
        set('time', d.scalTime);
        set('place', d.scalPlace);
        set('slots', d.scalSlots);
        set('people', d.scalPeople);

        const hoursRow = dialog.querySelector('[data-scal-hours-row]');
        if (hoursRow) {
            hoursRow.hidden = !d.scalHours;
            set('hours', d.scalHours);
        }

        dialog.querySelector('[data-f="id"]').value = d.scalId;
        const join = dialog.querySelector('[data-scal-join]');
        const leave = dialog.querySelector('[data-scal-leave]');
        const closed = dialog.querySelector('[data-scal-closed]');
        const canJoin = d.scalOpen === '1';
        const going = d.scalGoing === '1';
        join.hidden = !canJoin;
        leave.hidden = !going;
        if (going && d.scalWithdrawUrl) leave.action = d.scalWithdrawUrl;
        closed.hidden = canJoin || going;
    }

    if (dialog && typeof dialog.showModal === 'function') {
        root.addEventListener('click', function (e) {
            if (e.target.closest('.scal-chip__check') || e.target.closest('summary')) return;
            const chip = e.target.closest('[data-scal-turno]');
            if (!chip) return;
            e.preventDefault();
            fill(chip);
            dialog.showModal();
        });

        dialog.addEventListener('click', function (e) {
            if (e.target.closest('[data-scal-close]') || e.target === dialog) dialog.close();
        });
    }

    refresh();
})();
