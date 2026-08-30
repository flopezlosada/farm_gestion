/*
 * csa-validate — los avisos de "falta esto" en castellano y dentro del diseño.
 *
 * El navegador trae validación nativa gratis, pero su globo tiene dos defectos
 * que en esta aplicación se notan mucho:
 *
 *  · Habla en el idioma del NAVEGADOR, no en el de la página. A quien tiene el
 *    sistema en inglés le sale "Please fill in this field." en mitad de una
 *    pantalla en castellano, y este software lo usa gente que no tiene por qué
 *    saber inglés.
 *  · Señala al control REAL, y cuando un <select> está oculto porque lo
 *    sustituye el dropdown csa, el globo aparece flotando sobre nada.
 *
 * Así que se corta el globo (el evento 'invalid' es cancelable), se marca el
 * campo y se escribe lo que falta AL FINAL DEL FORMULARIO, en un solo bloque.
 * Al final y no bajo cada campo a propósito: estos formularios son filas en
 * línea —"Quién · Horas · [Anotar]"— y meter un párrafo bajo un campo empuja a
 * los demás y descoloca la fila entera.
 *
 * Activación opt-in: <form data-csa-validate>.
 *
 * SIN JAVASCRIPT no se pierde nada: el navegador sigue validando por su cuenta,
 * con su globo feo pero funcionando. Y el servidor valida igual, que es donde
 * de verdad se decide.
 */
(function () {
    'use strict';

    /* Qué se dice de cada tipo de fallo. El navegador distingue muchos más; se
       cubren los que estos formularios pueden producir y el resto cae en el
       genérico. */
    function messageFor(field) {
        var custom = field.dataset.invalidMessage;
        if (custom) {
            return custom;
        }

        var name = labelOf(field);
        var v = field.validity;

        if (v.valueMissing) {
            return 'Falta rellenar «' + name + '».';
        }
        if (v.rangeUnderflow) {
            return '«' + name + '» tiene que ser al menos ' + (field.min || '0') + '.';
        }
        if (v.rangeOverflow) {
            return '«' + name + '» no puede pasar de ' + field.max + '.';
        }
        if (v.stepMismatch) {
            return '«' + name + '» va de ' + (field.step || '1') + ' en ' + (field.step || '1') + '.';
        }
        if (v.typeMismatch || v.badInput) {
            return '«' + name + '» no tiene un valor válido.';
        }

        return 'Revisa «' + name + '».';
    }

    /* El nombre humano del campo sale de su <label>, que es lo que la persona
       está leyendo. Se le quita el texto de los controles que lleve dentro para
       que no salga "Horas 4" ni el contenido de un desplegable. */
    function labelOf(field) {
        var label = field.closest('label');

        if (!label) {
            var byId = field.id && document.querySelector('label[for="' + field.id + '"]');
            label = byId || null;
        }

        if (!label) {
            return field.name || 'este campo';
        }

        var clone = label.cloneNode(true);
        Array.prototype.slice
            .call(clone.querySelectorAll('input, select, textarea, .csa-dropdown'))
            .forEach(function (el) {
                el.remove();
            });

        return clone.textContent.trim().replace(/\s+/g, ' ') || field.name || 'este campo';
    }

    function box(form) {
        var existing = form.querySelector('.csa-form-errors');
        if (existing) {
            return existing;
        }

        var el = document.createElement('div');
        el.className = 'csa-form-errors';
        el.setAttribute('role', 'alert');
        form.appendChild(el);

        return el;
    }

    function clear(form) {
        var el = form.querySelector('.csa-form-errors');
        if (el) {
            el.remove();
        }
        Array.prototype.slice
            .call(form.querySelectorAll('.is-invalid'))
            .forEach(function (field) {
                field.classList.remove('is-invalid');
                field.removeAttribute('aria-invalid');
            });
    }

    function init(form) {
        if (form.dataset.csaValidateReady) {
            return;
        }
        form.dataset.csaValidateReady = '1';

        // En captura: 'invalid' no burbujea, así que un listener en el
        // formulario sólo lo ve bajando.
        form.addEventListener('invalid', function (e) {
            var field = e.target;

            // Cortar el globo del navegador. El evento es cancelable justo para
            // esto: permite validar con las reglas nativas y presentarlo a mano.
            e.preventDefault();

            // El primero manda: es donde el navegador habría llevado el foco, y
            // corregirlo suele arreglar la mitad de lo demás.
            var primero = !form.querySelector('.is-invalid');

            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');

            var linea = document.createElement('p');
            linea.textContent = messageFor(field);
            box(form).appendChild(linea);

            if (primero) {
                // El <select> de un dropdown csa está oculto: el foco va al
                // control que se ve, si lo hay.
                var visible = field.closest('.csa-dropdown');
                visible = visible ? visible.querySelector('.csa-dropdown__trigger') : field;
                if (visible && visible.focus) {
                    visible.focus();
                }
            }
        }, true);

        // Al reintentar se limpia todo antes de volver a validar, para no
        // apilar el mismo aviso una vez por cada clic en el botón.
        form.addEventListener('submit', function () {
            clear(form);
        }, true);

        form.addEventListener('input', function (e) {
            if (e.target.classList.contains('is-invalid') && e.target.checkValidity()) {
                clear(form);
            }
        });

        form.addEventListener('change', function (e) {
            if (e.target.classList.contains('is-invalid') && e.target.checkValidity()) {
                clear(form);
            }
        });
    }

    function boot() {
        Array.prototype.slice
            .call(document.querySelectorAll('form[data-csa-validate]'))
            .forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
