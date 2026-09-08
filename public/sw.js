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

// TOMA EL CONTROL EN CUANTO SE INSTALA, sin esperar a que se cierren todas las
// pestañas del sitio. Por defecto un service worker nuevo se queda "en espera"
// mientras el viejo siga atendiendo alguna pestaña abierta, y eso aquí significa
// que un arreglo del aviso —un icono que faltaba, un enlace mal— tarda días en
// llegar a quien tiene la web abierta a diario. Se notó al añadir el icono: el
// aviso siguió saliendo pelado porque lo atendía el service worker anterior.
//
// El riesgo habitual de skipWaiting es dejar la página con assets cacheados de
// una versión y código de otra. Aquí no aplica: este service worker NO cachea
// nada —no escucha 'fetch'— y sólo atiende 'push' y 'notificationclick', que son
// autónomos.
self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

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
        vibrate: [200, 100, 200],
        // EL LOGO, que es lo que dice de quién es el aviso sin gastar ni una
        // letra del título. Es el mismo icono que declara el manifest, así que
        // la notificación y la app instalada se reconocen igual.
        icon: '/icon-192.png',
        // Y el badge, que es OTRA COSA: el iconito de la barra de estado de
        // Android. Va aparte porque allí sólo se usa el canal alfa como máscara
        // —un icono a color saldría como un cuadrado blanco— así que es el árbol
        // en silueta sobre transparente. Sale de icon-192.png con el script de
        // docs/logo/. A tamaño de barra de estado las ramas se pierden y queda
        // la mancha del árbol; sigue siendo mejor que el icono genérico que pone
        // Android cuando no se declara ninguno.
        badge: '/icon-badge-96.png'
        // SIN requireInteraction, a diferencia de gestion-centro. Allí un aviso
        // de guardia se queda en pantalla hasta que se atiende porque si no se
        // queda un aula sin cubrir. Aquí es "hace falta gente para el jueves":
        // dejarlo clavado en la pantalla del móvil hasta que lo descartes es
        // justo el tipo de insistencia que hace que la gente apague los avisos,
        // y el permiso del navegador no se puede volver a pedir.
        //
        // SIGUE SIN BADGE, que es otra cosa que el icono: Android pide un PNG de
        // 96x96 MONOCROMO sobre transparente y usa sólo su canal alfa como
        // máscara, así que el icono a color de 192 saldría ahí como un cuadrado
        // blanco. Mientras no exista ese fichero, no se declara: apuntar a uno
        // que no está deja el aviso igual de pelado, pero con un 404 por medio.
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
