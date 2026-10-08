/*
 * csa-submit-once — un formulario POST sólo se envía una vez.
 *
 * Un doble clic en «Guardar» manda dos peticiones antes de que la primera
 * cambie de página, y cada una crea su registro: dos apuntes iguales en el
 * libro, dos cestas extra, dos altas. El servidor no tiene forma general de
 * saber que la segunda es un eco de la primera, así que se corta aquí.
 *
 * GLOBAL A PROPÓSITO, no opt-in como el resto de csa-*: es una red de
 * seguridad, y un formulario que se olvide de pedirla es justo el que acaba
 * duplicando.
 *
 * Qué NO se toca:
 *  · Un envío que otro manejador ha cancelado (un confirm() al que se dice que
 *    no, una validación): el formulario sigue disponible.
 *  · Los GET (filtros, buscadores): repetirlos no crea nada.
 *  · Los que abren otra pestaña (target="_blank", PDF imprimibles): la página
 *    se queda y volver a pulsar es legítimo.
 *
 * El botón se deshabilita EN DIFERIDO: uno deshabilitado no viaja en el envío,
 * y su name es lo que distingue «Guardar» de «Guardar y anotar otro». Con
 * data-busy-label el botón dice qué está pasando («Añadiendo…»).
 *
 * Si la respuesta no cambia de página (una descarga) o se vuelve atrás con la
 * caché del navegador, el formulario se libera solo.
 */
(function () {
    'use strict';

    /** Lo bastante para cubrir cualquier doble clic; no tanto como para dejar un formulario muerto. */
    var RELEASE_AFTER_MS = 10000;

    function opensElsewhere(form, submitter) {
        var target = (submitter && submitter.getAttribute('formtarget')) || form.getAttribute('target') || '';

        return target !== '' && target !== '_self';
    }

    function methodOf(form, submitter) {
        return ((submitter && submitter.getAttribute('formmethod')) || form.getAttribute('method') || 'get').toLowerCase();
    }

    function buttonsOf(form) {
        return Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'))
            .concat(form.id ? Array.prototype.slice.call(document.querySelectorAll('[form="' + form.id + '"][type="submit"]')) : []);
    }

    function lock(form, submitter) {
        form.dataset.csaSubmitting = '1';
        form.setAttribute('aria-busy', 'true');

        setTimeout(function () {
            buttonsOf(form).forEach(function (button) {
                button.dataset.csaWasDisabled = button.disabled ? '1' : '';
                button.disabled = true;
            });
            if (submitter && submitter.dataset.busyLabel) {
                submitter.dataset.csaLabel = submitter.textContent;
                submitter.textContent = submitter.dataset.busyLabel;
            }
        }, 0);

        form.csaRelease = setTimeout(function () { release(form); }, RELEASE_AFTER_MS);
    }

    function release(form) {
        clearTimeout(form.csaRelease);
        delete form.dataset.csaSubmitting;
        form.removeAttribute('aria-busy');

        buttonsOf(form).forEach(function (button) {
            button.disabled = button.dataset.csaWasDisabled === '1';
            delete button.dataset.csaWasDisabled;
            if (button.dataset.csaLabel !== undefined) {
                button.textContent = button.dataset.csaLabel;
                delete button.dataset.csaLabel;
            }
        });
    }

    // En document y en fase de burbuja: así los manejadores del propio
    // formulario (confirm, validación) ya han decidido si el envío sale.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var submitter = event.submitter || null;

        if (!(form instanceof HTMLFormElement) || methodOf(form, submitter) !== 'post' || opensElsewhere(form, submitter)) {
            return;
        }
        if (form.dataset.csaSubmitting) {
            event.preventDefault();

            return;
        }
        if (event.defaultPrevented) {
            return;
        }

        lock(form, submitter);
    });

    // Volver atrás desde la página siguiente puede restaurar ésta tal cual,
    // con los botones deshabilitados.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            Array.prototype.forEach.call(document.querySelectorAll('form[data-csa-submitting]'), release);
        }
    });
})();
