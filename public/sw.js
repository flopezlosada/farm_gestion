/**
 * Service worker mínimo para los avisos push. NO cachea nada: esto no es una
 * PWA offline. Su único cometido es recibir el mensaje del servidor, mostrarlo,
 * y abrir la página correcta al pulsarlo.
 *
 * Vive en la raíz (/sw.js) a propósito: el alcance de un service worker es el
 * directorio desde el que se sirve, así que desde /js/ no cubriría la app.
 *
 * El payload es el JSON que manda App\Service\Push\PushSender:
 * { title, body, url }.
 */
'use strict';

/** A dónde va un aviso que llegó sin URL (no debería pasar, pero pasa). */
var DEFAULT_URL = '/panel/voluntariado';

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        // Un payload que no es JSON no debe perder el aviso: se enseña el texto
        // crudo, que es mejor que un silencio.
        data = { title: 'Aviso', body: event.data ? event.data.text() : '' };
    }

    var options = {
        body: data.body || '',
        // La URL a abrir viaja en data para poder leerla en notificationclick.
        data: { url: data.url || DEFAULT_URL },
        // Vibración corta en móvil, para que se note en el bolsillo.
        vibrate: [200, 100, 200]
        // SIN requireInteraction, a diferencia de gestion-centro. Allí un aviso
        // de guardia se queda en pantalla hasta que se atiende porque si no se
        // queda un aula sin cubrir. Aquí es "hace falta gente para el jueves":
        // dejarlo clavado en la pantalla del móvil hasta que lo descartes es
        // justo el tipo de insistencia que hace que la gente apague los avisos,
        // y el permiso del navegador no se puede volver a pedir.
        //
        // SIN icon ni badge mientras no haya PNG que poner: hacen falta un icono
        // de 192x192 a color y un badge de 96x96 monocromo sobre transparente
        // (Android usa sólo su canal alfa como máscara, así que un cuadrado a
        // color saldría como un cuadrado blanco). Apuntar a un fichero que no
        // existe deja el aviso sin icono igualmente, pero con un 404 por medio.
    };

    event.waitUntil(self.registration.showNotification(data.title || 'Aviso', options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || DEFAULT_URL;

    // Si ya hay una pestaña de la web abierta, se enfoca y se navega; si no, se
    // abre una nueva. Sin esto, cada aviso pulsado deja una pestaña más.
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
