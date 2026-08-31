<?php

namespace App\Service\Notification;

use App\Entity\Notification;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A dónde lleva un aviso. LA ÚNICA definición de ese destino.
 *
 * EXISTE PARA QUE NO HAYA DOS. Un mismo aviso se entrega hoy por dos vías que
 * abren una pantalla: la fila de la bandeja y la notificación del móvil. Cuando
 * cada una calcula su destino por su cuenta —y era lo que pasaba: el push de la
 * cesta generaba `panel` en {@see \App\Service\Delivery\PickupReminderPusher} y
 * el del voluntariado llevaba la cadena '/panel/voluntariado' escrita a mano en
 * dos ficheros— cambiar una pantalla de sitio arregla una vía y deja la otra
 * apuntando a donde ya no hay nada. Y no se nota: el que se rompe es el canal que
 * quien lo cambió no estaba mirando.
 *
 * EL DESTINO SALE DEL `kind`, no de una columna. Es la contrapartida de que
 * {@see Notification} no guarde a qué apunta: aquí se decide, por familia de
 * aviso, cuál es la pantalla que contesta a lo que ese aviso dice. Añadir un
 * aviso nuevo es añadir un caso a este match; olvidarlo no rompe nada, cae en la
 * bandeja.
 *
 * SIEMPRE UN PATH RELATIVO, nunca una URL absoluta: quien más pregunta es el
 * planificador, que corre desde la consola y no tiene petición de la que sacar el
 * host, y el service worker del navegador ya lo resuelve contra el origen de la
 * web.
 */
class NotificationLink
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * El destino de un aviso ya guardado.
     *
     * @param Notification $notification el aviso
     *
     * @return string el path que abre
     */
    public function pathFor(Notification $notification): string
    {
        return $this->pathForKind($notification->getKind());
    }

    /**
     * El destino de una clase de aviso, sin necesidad de tener la fila delante.
     *
     * Lo usan los envíos para el payload del push, que se manda en el mismo
     * momento en que se escribe la copia de la bandeja y con el mismo `kind`. Que
     * los dos pasen por aquí es lo que garantiza que no discrepen.
     *
     * @param string $kind una de las constantes Notification::KIND_*
     *
     * @return string el path que abre
     */
    public function pathForKind(string $kind): string
    {
        return match (true) {
            // El aviso de la cesta lleva al panel y no al calendario: dice "te
            // toca el miércoles", y el panel es la pantalla del "qué me toca",
            // con la próxima entrega, el nodo y la hora arriba del todo. Quien
            // además quiera mover la cesta tiene el calendario a un clic; quien
            // sólo quiera confirmar el día no tiene que leerse una rejilla de
            // doce semanas para encontrarlo.
            str_starts_with($kind, 'pickup.') => $this->urlGenerator->generate('panel'),
            // Los de voluntariado —piden gente, o recuerdan lo que te toca—
            // llevan a la pantalla de voluntariado del socix, que enseña las dos
            // cosas: lo abierto y lo que llevas apuntado.
            str_starts_with($kind, 'volunteering.') => $this->urlGenerator->generate('panel_volunteering'),
            // "Faltan datos en tu ficha" lleva a la pantalla donde se rellenan, y
            // no a la bandeja: el aviso ya dice qué falta, así que lo único que
            // queda por hacer es el formulario. Un aviso que pide algo tiene que
            // abrir el sitio donde se hace.
            str_starts_with($kind, 'profile.') => $this->urlGenerator->generate('panel_profile'),
            // El de quien coordina lleva al listado de fichas a medias, que es
            // donde están todas con lo que le falta a cada una. El aviso es un
            // resumen ("12 fichas..."), así que sin este destino no sería
            // accionable: diría cuántas son y no cuáles.
            str_starts_with($kind, 'partners.') => $this->urlGenerator->generate('partner_incomplete_profiles'),
            // Cualquier otro no tiene mejor sitio que la bandeja. Es el caso de
            // un aviso viejo cuyo `kind` ya no se emite, y de uno nuevo al que se
            // le olvidó su línea aquí: molesta, pero no deja a nadie en un 404.
            default => $this->urlGenerator->generate('notification_inbox'),
        };
    }
}
