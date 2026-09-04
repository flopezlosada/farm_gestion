/*
 * csa-reveal — enseña un campo sólo cuando el valor de otro lo pide.
 *
 * El formulario de una tarea de voluntariado tiene veinte campos y se
 * rellenan ocho: «cada cuántas semanas» no significa nada si la tarea es de
 * una sola vez, y «sitio» y «punto de recogida» sobran si se hace desde casa.
 * Enseñarlo todo a la vez lía, y lo que lía se rellena mal.
 *
 * Uso, en el envoltorio del campo que depende de otro:
 *
 *     <div data-csa-show-when="volunteer_offer[repeatType]=weekly|monthly">
 *
 * A la izquierda del «=», el `name` del control del que depende; a la
 * derecha, los valores con los que se enseña, separados por «|». Para una
 * casilla los valores son `checked` y `unchecked`. Varias reglas van separadas
 * por «;» y tienen que cumplirse todas: «hasta el» sólo se enseña si la tarea
 * se repite Y no está marcada como sin fin.
 *
 * Al esconder un envoltorio se le quita el `required` a lo que lleve dentro,
 * y se le devuelve al enseñarlo: el navegador no deja enviar un formulario
 * con un campo obligatorio vacío, y si ese campo está escondido nadie puede
 * rellenarlo ni ver qué falla. Nada más se toca: los valores escondidos viajan
 * igual y es el servidor quien decide qué vale, como siempre.
 *
 * SIN JAVASCRIPT se ve todo, que es el estado de siempre: no se pierde nada.
 */
(function () {
    'use strict';

    /* El valor del control del que se depende, en la forma en que lo escribe
       la regla: lo que hay seleccionado, o `checked`/`unchecked` en una
       casilla. Null si el control no está en el formulario, y entonces el
       campo se enseña: mejor un campo de más que uno imposible de alcanzar. */
    function valueOf(form, name) {
        var control = form.querySelector('[name="' + name + '"]');
        if (!control) {
            return null;
        }

        if (control.type === 'checkbox') {
            return control.checked ? 'checked' : 'unchecked';
        }

        if (control.type === 'radio') {
            var checked = form.querySelector('[name="' + name + '"]:checked');
            return checked ? checked.value : '';
        }

        return control.value;
    }

    function parse(spec) {
        return spec.split(';').map(function (rule) {
            var at = rule.indexOf('=');

            return {
                name: rule.slice(0, at),
                values: rule.slice(at + 1).split('|')
            };
        });
    }

    /* Una regla se cumple con el valor del control, o si el control no está
       en el formulario: mejor un campo de más que uno imposible de alcanzar. */
    function holds(form, rule) {
        var value = valueOf(form, rule.name);

        return value === null || rule.values.indexOf(value) !== -1;
    }

    function setRequired(wrapper, on) {
        Array.prototype.slice
            .call(wrapper.querySelectorAll('input, select, textarea'))
            .forEach(function (field) {
                if (on && field.dataset.csaWasRequired) {
                    field.required = true;
                    delete field.dataset.csaWasRequired;
                } else if (!on && field.required) {
                    field.required = false;
                    field.dataset.csaWasRequired = '1';
                }
            });
    }

    function apply(form) {
        Array.prototype.slice
            .call(form.querySelectorAll('[data-csa-show-when]'))
            .forEach(function (wrapper) {
                var show = parse(wrapper.dataset.csaShowWhen).every(function (rule) {
                    return holds(form, rule);
                });

                wrapper.hidden = !show;
                setRequired(wrapper, show);
            });
    }

    function init(form) {
        if (form.dataset.csaRevealReady) {
            return;
        }
        form.dataset.csaRevealReady = '1';

        apply(form);
        form.addEventListener('change', function () {
            apply(form);
        });
        form.addEventListener('input', function () {
            apply(form);
        });
    }

    function boot() {
        Array.prototype.slice
            .call(document.querySelectorAll('[data-csa-show-when]'))
            .map(function (wrapper) {
                return wrapper.closest('form');
            })
            .filter(function (form, index, all) {
                return form && all.indexOf(form) === index;
            })
            .forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
