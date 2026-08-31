/*
 * push — alta y baja de los avisos push del navegador.
 *
 * Opt-in y sólo por gesto: se engancha a cualquier elemento con
 * [data-push-toggle] y no pide permiso hasta que se pulsa. Los navegadores lo
 * exigen, pero además es la única forma decente de hacerlo: un diálogo de
 * permisos que salta solo al entrar se deniega por reflejo, y un permiso
 * denegado NO se puede volver a pedir — requestPermission() ya ni llega a
 * enseñar el diálogo y hay que entrar a mano en los ajustes del sitio, cosa que
 * no hace nadie. Se gasta una vez.
 *
 * NAVEGACIÓN PRIVADA: el push NO existe ahí, en ninguna forma. Chrome de
 * incógnito devuelve el permiso como denegado sin llegar a enseñar el diálogo,
 * y Firefox privado ni siquiera expone PushManager. No es algo que se pueda
 * desbloquear en los ajustes: hay que abrir la web en una ventana normal. Se
 * dice en el texto de ayuda porque, si no, "avisos bloqueados" se lee como una
 * avería de la web y se pierde media tarde buscando el ajuste que no existe.
 *
 * EN iOS hay un muro añadido: el push sólo funciona si la web está instalada en
 * la pantalla de inicio (Compartir → Añadir a inicio). Una pestaña normal no
 * vale, y Apple no implementa el banner de instalación, así que hay que
 * explicarlo a mano. Por eso, en iOS sin instalar, el botón no pide permiso: da
 * las instrucciones.
 */
(function () {
    'use strict';

    var ENDPOINTS = {
        publicKey: '/push/clave-publica',
        subscribe: '/push/suscribir',
        unsubscribe: '/push/desuscribir'
    };

    /**
     * Si el navegador tiene todas las piezas. Firefox en modo privado, por
     * ejemplo, tiene ServiceWorker pero no PushManager.
     */
    function isSupported() {
        return 'serviceWorker' in navigator
            && 'PushManager' in window
            && 'Notification' in window;
    }

    /** Si estamos en iOS o iPadOS. */
    function isIos() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    /**
     * Si la web se está viendo como aplicación instalada. En iOS es la
     * condición para que el push exista siquiera: dentro de la app instalada la
     * API está, y en el navegador no, aunque sea el mismo dispositivo.
     */
    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    }

    /**
     * La clave pública VAPID viene en Base64URL y PushManager la quiere como
     * Uint8Array. Sin esta conversión, subscribe() falla con un error que no
     * dice nada útil.
     */
    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var output = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            output[i] = raw.charCodeAt(i);
        }
        return output;
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
    }

    /**
     * La salida del callejón, cuando la hay. Un botón que dice "bloqueado" y se
     * deshabilita deja a la persona sin nada que hacer, y esto no se arregla
     * volviendo a pulsar: el permiso denegado no se puede volver a pedir desde
     * la web NUNCA, sólo desde los ajustes del navegador. Quien no sepa que eso
     * existe da por hecho que la web está rota.
     */
    var HINTS = {
        blocked: 'Este navegador tiene bloqueados los avisos de esta web, y desde aquí no se pueden volver a pedir. Para desbloquearlos: pulsa el icono que hay a la izquierda de la dirección (un candado o unos deslizadores), busca «Notificaciones», ponlo en «Permitir» y recarga esta página. Si estás en una ventana de incógnito o privada, no hay nada que desbloquear: ahí los avisos no se pueden activar nunca. Abre la web en una ventana normal.',

        // Va en el estado ACTIVADO y no en la pantalla, porque sólo le sirve a
        // quien ya los tiene puestos: al que aún no los ha activado le sobra, y
        // en la tarjeta se leía como una pega antes siquiera de probar.
        //
        // Y hace falta decirlo porque este fallo es MUDO: si el sistema tiene
        // apagados los avisos del navegador, la web no se entera de nada
        // —Notification.permission sigue diciendo «granted» y showNotification()
        // resuelve como si los hubiera pintado—, así que aquí seguiría poniendo
        // «activados» sin que llegue nunca nada.
        on: 'Si no te llega ninguno, revisa los ajustes de notificaciones de tu móvil u ordenador: desde aquí no hay forma de saber si el sistema los tiene apagados.',

        // Estado propio para iOS sin instalar, separado de «este navegador no
        // admite avisos»: aquí SÍ se puede, pero hay que dar un paso más, y
        // decirle a alguien que su iPhone no admite avisos es mentira y le hace
        // abandonar.
        ios: 'En iPhone y iPad, Apple sólo permite los avisos si la web está añadida a la pantalla de inicio: pulsa Compartir (el cuadrado con la flecha hacia arriba), elige «Añadir a pantalla de inicio», y abre la web desde el icono que aparezca. Desde ahí ya podrás activarlos.'
    };

    /**
     * Qué se lee en cada estado: el ESTADO (frase corta, siempre visible), la
     * ACCIÓN del botón (null = no hay nada que pulsar) y la EXPLICACIÓN (sólo
     * cuando hace falta).
     *
     * Separadas porque antes el botón cargaba con las tres cosas y decía
     * "Avisos activados": ¿es lo que pasa o lo que va a pasar si lo pulso? Un
     * botón dice lo que HACE; el estado se lee, no se pulsa.
     */
    var STATES = {
        checking:    { state: 'Comprobando…',                              action: null },
        off:         { state: 'Desactivado en este dispositivo.',              action: 'Activar' },
        on:          { state: 'Activado en este dispositivo.',                 action: 'Desactivar',
                       note: HINTS.on },
        working:     { state: 'Un momento…',                               action: null },
        failed:      { state: 'No se pudo activar. Inténtalo otra vez.',   action: 'Activar' },
        blocked:     { state: 'Bloqueado en este navegador.',              action: null,
                       note: HINTS.blocked },
        unsupported: { state: 'Este navegador no admite avisos.',          action: null },
        ios:         { state: 'Falta un paso en iPhone y iPad.',           action: null,
                       note: HINTS.ios }
    };

    /**
     * Las tres zonas de un toggle. La plantilla las trae hechas; si faltara
     * alguna se devuelve null y {@see setState} sigue funcionando con las que
     * haya, para que una pantalla que sólo ponga el botón no reviente.
     */
    function partsOf(button) {
        var box = button.closest('.csa-push') || button.parentElement;

        return {
            status: box ? box.querySelector('[data-push-status]') : null,
            note: box ? box.querySelector('[data-push-hint]') : null
        };
    }

    function setState(button, state) {
        var spec = STATES[state] || STATES.off;
        var parts = partsOf(button);

        button.dataset.pushState = state;

        // Sin acción posible, el botón se esconde en vez de quedarse gris: un
        // botón deshabilitado invita a pulsarlo y no explica nada.
        if (spec.action) {
            button.textContent = spec.action;
            button.hidden = false;
            button.disabled = false;
        } else {
            button.hidden = true;
        }

        if (parts.status) {
            parts.status.textContent = spec.state;
        }

        if (parts.note) {
            parts.note.textContent = spec.note || '';
            parts.note.hidden = !spec.note;
        }
    }

    /** Suscribe este navegador y lo registra en el servidor. */
    function subscribe(button) {
        setState(button, 'working');

        fetch(ENDPOINTS.publicKey, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.publicKey) {
                    throw new Error('El servidor no tiene configurados los avisos push.');
                }
                return Notification.requestPermission().then(function (permission) {
                    if (permission !== 'granted') {
                        // No se vuelve a pedir: a partir de aquí, sólo desde los
                        // ajustes del navegador.
                        throw new Error('denied');
                    }
                    return navigator.serviceWorker.register('/sw.js').then(function () {
                        return navigator.serviceWorker.ready;
                    }).then(function (registration) {
                        return registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(data.publicKey)
                        });
                    });
                });
            })
            .then(function (subscription) {
                return postJson(ENDPOINTS.subscribe, subscription.toJSON());
            })
            .then(function () {
                setState(button, 'on');
            })
            .catch(function (error) {
                if (error && error.message === 'denied') {
                    setState(button, 'blocked');
                    return;
                }
                setState(button, 'failed');
            });
    }

    /** Da de baja este navegador, en el navegador y en el servidor. */
    function unsubscribe(button) {
        setState(button, 'working');

        navigator.serviceWorker.ready
            .then(function (registration) { return registration.pushManager.getSubscription(); })
            .then(function (subscription) {
                if (!subscription) {
                    return null;
                }
                var endpoint = subscription.endpoint;
                return subscription.unsubscribe().then(function () {
                    return postJson(ENDPOINTS.unsubscribe, { endpoint: endpoint });
                });
            })
            .then(function () {
                setState(button, 'off');
            })
            .catch(function () {
                setState(button, 'off');
            });
    }

    /** Deja el botón reflejando el estado real, sin pedir nada. */
    function refresh(button) {
        if (!isSupported()) {
            setState(button, 'unsupported');
            return;
        }

        if (isIos() && !isStandalone()) {
            // Estado propio, no 'unsupported': desde esta pestaña la API no
            // existe, pero en el mismo dispositivo SÍ funciona una vez instalada. El
            // hint de abajo explica cómo, que es la diferencia entre "no puedo" y
            // "no sé cómo".
            setState(button, 'ios');
            return;
        }

        if (Notification.permission === 'denied') {
            setState(button, 'blocked');
            return;
        }

        navigator.serviceWorker.getRegistration().then(function (registration) {
            if (!registration) {
                setState(button, 'off');
                return;
            }
            registration.pushManager.getSubscription().then(function (subscription) {
                setState(button, subscription ? 'on' : 'off');
            });
        });
    }

    function init() {
        var buttons = document.querySelectorAll('[data-push-toggle]');
        Array.prototype.forEach.call(buttons, function (button) {
            // Comprobando… hasta que refresh() resuelva: getSubscription() es
            // asíncrono y sin esto la tarjeta parpadea el estado equivocado.
            setState(button, 'checking');
            refresh(button);
            button.addEventListener('click', function () {
                if (button.dataset.pushState === 'on') {
                    unsubscribe(button);
                } else if (button.dataset.pushState === 'off') {
                    subscribe(button);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
