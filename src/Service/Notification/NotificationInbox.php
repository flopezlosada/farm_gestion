<?php

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deja el aviso en la bandeja de quien lo recibe. La única puerta de escritura de
 * {@see Notification}.
 *
 * NO DECIDE NADA, y menos que nada si avisar. Los envíos de la asociación ya
 * deciden a quién y qué decirle; aquí sólo se guarda la copia que no se pierde.
 * Quienes escriben hoy: el aviso de la cesta ({@see \App\Service\Delivery\PickupReminderPusher}),
 * la llamada de voluntariado ({@see \App\Service\Volunteering\VolunteerCallNotifier}),
 * el recordatorio de la tarea ({@see \App\Command\SendVolunteerRemindersCommand}),
 * el cambio o la anulación de una tarea ({@see \App\Service\Volunteering\VolunteerOfferChangeNotifier}),
 * los huevos retirados o trasladados ({@see \App\Service\Delivery\EggRescheduleNotifier}),
 * el cambio de reparto anulado por un cierre ({@see \App\Service\Delivery\ClosureShiftNotifier})
 * y las fichas con datos que faltan ({@see IncompleteProfileNotifier}).
 *
 * NO CONSULTA {@see NotificationPreferences}, y es la regla que hay que respetar
 * al llamarla. La bandeja es el suelo: se escribe aunque el socix haya apagado el
 * correo y el móvil, porque es exactamente lo que hace que apagarlos se pueda
 * permitir. De ahí que en los envíos la llamada vaya SIEMPRE ANTES del filtro de
 * preferencias — después, quien más necesita la copia sería justo el único que no
 * la tendría. Y en los que salen sólo por correo, ANTES de comprobar si hay
 * dirección: en el padrón real la mayoría de las fichas no la tienen informada.
 *
 * NO ES EL DESPACHADOR DE CANALES, aunque en gestión-centro la clase equivalente
 * sí lo sea. Allí un único sitio persiste el aviso y elige por dónde entregarlo;
 * aquí los canales llevan tiempo montados en cuatro servicios, cada uno con sus
 * preferencias, su clase de efecto en {@see \App\Service\Cron\EffectLedger} y su
 * forma de fallar, y fundirlos en uno es reescribir los cuatro. Lo que sí se
 * comparte —y era lo importante— es el destino del aviso, que sale de
 * {@see NotificationLink} tanto para la fila como para el push.
 *
 * LA IDEMPOTENCIA ES DE QUIEN LLAMA. Esta clase escribe lo que le pidan, tantas
 * veces como se lo pidan; que un tick del planificador no deje el mismo aviso dos
 * veces lo garantiza el apunte del ledger que ya envuelve cada envío.
 */
class NotificationInbox
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Prepara un aviso SIN guardarlo todavía.
     *
     * Para las tandas: el recordatorio de la cesta escribe un aviso por socix, con
     * su fecha y su nodo, y hacer un flush por cada uno son doscientas escrituras
     * donde basta una. Se acumulan con este método y se cierran con
     * {@see flush()}.
     *
     * OJO: sin la llamada a {@see flush()} el aviso no llega a la base de datos.
     *
     * @param User        $recipient a quién va
     * @param string      $kind      una de las constantes Notification::KIND_*
     * @param string      $title     lo que se lee de un vistazo
     * @param string|null $body      el detalle, si hay algo más que decir
     *
     * @return Notification el aviso preparado
     */
    public function record(User $recipient, string $kind, string $title, ?string $body = null): Notification
    {
        $notification = new Notification($recipient, $kind, $title, $body);
        $this->entityManager->persist($notification);

        return $notification;
    }

    /**
     * Guarda los avisos preparados con {@see record()}.
     */
    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * El mismo aviso a varias cuentas, guardado ya.
     *
     * Es el caso del voluntariado: cuando falta gente para una tarea, el texto es
     * idéntico para todo el mundo y se manda una vez. Una fila por cuenta y no una
     * compartida, porque cada cual la abre —o no— por su cuenta, y es lo que
     * cuenta la campanita.
     *
     * @param list<User>  $recipients a quiénes va
     * @param string      $kind       una de las constantes Notification::KIND_*
     * @param string      $title      lo que se lee de un vistazo
     * @param string|null $body       el detalle, si hay algo más que decir
     *
     * @return int cuántos avisos se han guardado
     */
    public function deliver(array $recipients, string $kind, string $title, ?string $body = null): int
    {
        if ([] === $recipients) {
            return 0;
        }

        foreach ($recipients as $recipient) {
            $this->record($recipient, $kind, $title, $body);
        }

        $this->flush();

        return \count($recipients);
    }
}
