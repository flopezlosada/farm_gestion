<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\SharedBasketChangeRequest;
use App\Entity\User;
use App\Entity\WeeklyBasketGroup;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Service\Notification\StaffAudience;
use App\Service\Push\PushSender;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Avisa a los DOS hogares de que su cesta compartida ha cambiado de día o de punto.
 *
 * A LOS DOS, INCLUIDO QUIEN LO PIDIÓ. La cesta es una y el cambio arrastra a ambos,
 * así que el aviso no es "te han cambiado la cesta" sino el acta de lo que quedó
 * acordado: quien lo pidió necesita ver que se aplicó, y quien no, enterarse. Sin
 * esto, alguien se planta el viernes viejo en el punto de recogida — que es el mismo
 * motivo por el que existe {@see EggRescheduleNotifier}.
 *
 * Correo transaccional: sale en la misma petición que aplica el cambio, no lo manda
 * el planificador. No lleva interruptor propio en /gestion/settings (no es un envío
 * recurrente), pero el maestro de envíos lo gobierna como a todos.
 *
 * LA COPIA EN LA BANDEJA SE ESCRIBE SIEMPRE, antes de mandar nada y sin mirar
 * preferencias: es el suelo de los otros dos canales, la regla que documenta
 * {@see NotificationInbox}. El correo y el push sí respetan lo que cada cual haya
 * apagado en su pantalla de avisos, bajo el tema de la cesta.
 */
final class SharedBasketChangeNotifier
{
    /**
     * A quién de dentro se avisa de un cambio de modalidad acordado. El de LECTURA y
     * no el de escritura: por jerarquía lo alcanza también quien edita, así que el
     * aviso llega a todo el que coordina socixs y no sólo a quien puede aplicarlo.
     * Mismo criterio que {@see \App\Service\Notification\IncompleteProfileNotifier}.
     */
    private const ROLE_PARTNERS = 'ROLE_GESTION_SOCIXS';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly UserRepository $users,
        private readonly NotificationInbox $inbox,
        private readonly NotificationPreferences $preferences,
        private readonly PushSender $push,
        private readonly NotificationLink $link,
        private readonly StaffAudience $staff,
    ) {
    }

    /**
     * Avisa del cambio de DÍA de una cesta compartida.
     *
     * @param list<Partner> $households Los dos hogares de la cesta.
     * @param Basket        $from       Semana en la que estaba.
     * @param Basket        $to         Semana a la que se ha movido.
     * @param string|null   $requestedBy Nombre de quien pidió el cambio, o null si lo
     *                                   aplicó administración por su cuenta.
     *
     * @return int Cuántos correos salieron.
     */
    public function moved(array $households, Basket $from, Basket $to, ?string $requestedBy = null): int
    {
        return $this->notify(
            $households,
            'Vuestra cesta compartida cambia de día · CSA Vega de Jarama',
            'Vuestra cesta compartida cambia de día',
            sprintf(
                'Del %s al %s.',
                $from->getDate()?->format('j/n') ?? '?',
                $to->getDate()?->format('j/n') ?? '?',
            ),
            'email/shared_basket_moved.html.twig',
            [
                'from_date' => $from->getDate(),
                'to_date' => $to->getDate(),
                'requested_by' => $requestedBy,
            ],
        );
    }

    /**
     * Avisa del cambio de PUNTO DE RECOGIDA de una cesta compartida en una semana.
     *
     * @param list<Partner>     $households  Los dos hogares de la cesta.
     * @param Basket            $basket      Semana afectada.
     * @param WeeklyBasketGroup $toGroup     Punto donde se recogerá esa semana.
     * @param string|null       $requestedBy Nombre de quien pidió el cambio, o null.
     *
     * @return int Cuántos correos salieron.
     */
    public function relocated(array $households, Basket $basket, WeeklyBasketGroup $toGroup, ?string $requestedBy = null): int
    {
        return $this->notify(
            $households,
            'Vuestra cesta compartida cambia de punto de recogida · CSA Vega de Jarama',
            'Vuestra cesta compartida cambia de punto',
            sprintf(
                'La del %s se recoge en %s.',
                $basket->getDate()?->format('j/n') ?? '?',
                $toGroup->getName() ?? 'otro punto',
            ),
            'email/shared_basket_relocated.html.twig',
            [
                'basket_date' => $basket->getDate(),
                'group_name' => $toGroup->getName(),
                'requested_by' => $requestedBy,
            ],
        );
    }

    /**
     * Avisa al otro hogar de que tiene una petición esperando su respuesta.
     *
     * Es el aviso del que depende toda la vía: sin él, la petición se queda en una
     * pantalla que ese hogar no tiene motivo para abrir.
     *
     * @param SharedBasketChangeRequest $request La petición recién guardada.
     *
     * @return int Cuántos correos salieron.
     */
    public function requested(SharedBasketChangeRequest $request): int
    {
        $counterpart = $request->getCounterpart();
        $requester = $request->getRequester();
        if (null === $counterpart || null === $requester) {
            return 0;
        }

        return $this->notify(
            [$counterpart],
            'Tienes que contestar a un cambio en vuestra cesta · CSA Vega de Jarama',
            'Un cambio en vuestra cesta espera tu respuesta',
            sprintf('%s pide %s.', $requester->getNameForDelivery(), $request->summary()),
            'email/shared_basket_request.html.twig',
            [
                'requester_name' => $requester->getNameForDelivery(),
                'summary' => $request->summary(),
                'note' => $request->getNote(),
            ],
            Notification::KIND_SHARED_REQUEST,
        );
    }

    /**
     * Avisa a quien pidió el cambio de que el otro hogar ha dicho que no.
     *
     * @param SharedBasketChangeRequest $request La petición rechazada.
     *
     * @return int Cuántos correos salieron.
     */
    public function rejected(SharedBasketChangeRequest $request): int
    {
        $counterpart = $request->getCounterpart();
        $requester = $request->getRequester();
        if (null === $counterpart || null === $requester) {
            return 0;
        }

        return $this->notify(
            [$requester],
            'Tu cambio en la cesta compartida no sale adelante · CSA Vega de Jarama',
            'El otro hogar no puede con ese cambio',
            sprintf('%s ha dicho que no a %s.', $counterpart->getNameForDelivery(), $request->summary()),
            'email/shared_basket_rejected.html.twig',
            [
                'other_name' => $counterpart->getNameForDelivery(),
                'summary' => $request->summary(),
            ],
        );
    }

    /**
     * Avisa de un cambio de modalidad que los dos hogares han acordado: a ellos, de
     * que queda en manos de administración, y a quien lleva socixs, de que hay algo
     * que aplicar.
     *
     * El aviso interno va SÓLO a la bandeja: es trabajo pendiente de una pantalla de
     * gestión, no algo que haya que sacar del correo a nadie.
     *
     * @param SharedBasketChangeRequest $request La petición aceptada.
     *
     * @return int Cuántos correos salieron a lxs socixs.
     */
    public function modalityAgreed(SharedBasketChangeRequest $request): int
    {
        $counterpart = $request->getCounterpart();
        $requester = $request->getRequester();
        if (null === $counterpart || null === $requester) {
            return 0;
        }

        $staff = $this->staff->withRole(self::ROLE_PARTNERS);
        if ([] !== $staff) {
            $this->inbox->deliver(
                $staff,
                Notification::KIND_PARTNERS_SHARED_CHANGE,
                'Cambio de modalidad acordado en una cesta compartida',
                sprintf(
                    '%s y %s han acordado %s. Falta aplicarlo.',
                    $requester->getNameForDelivery(),
                    $counterpart->getNameForDelivery(),
                    $request->summary(),
                ),
            );
        }

        return $this->notify(
            [$requester, $counterpart],
            'Vuestro cambio de modalidad queda acordado · CSA Vega de Jarama',
            'Cambio de modalidad acordado',
            sprintf('Los dos hogares habéis dicho que sí a %s. Lo aplica administración.', $request->summary()),
            'email/shared_basket_agreed.html.twig',
            [
                'summary' => $request->summary(),
                'requester_name' => $requester->getNameForDelivery(),
                'other_name' => $counterpart->getNameForDelivery(),
            ],
        );
    }

    /**
     * El envío en sí, por los tres canales.
     *
     * @param list<Partner>        $households A quiénes avisar.
     * @param string               $subject    Asunto del correo.
     * @param string               $title      Titular de la bandeja y del push.
     * @param string               $body       Detalle corto de la bandeja y del push.
     * @param string               $template   Plantilla HTML del correo (la de texto va junto a ella).
     * @param array<string, mixed> $context    Contexto de la plantilla; se le añade el nombre.
     * @param string               $kind       Constante Notification::KIND_* con la que se
     *                                         guarda la copia; decide a dónde lleva al abrirla.
     *
     * @return int Cuántos correos salieron.
     */
    private function notify(
        array $households,
        string $subject,
        string $title,
        string $body,
        string $template,
        array $context,
        string $kind = Notification::KIND_SHARED_CHANGE,
    ): int {
        // Las cuentas, UNA vez para los tres canales. Son dos hogares, así que no es un
        // problema de escala, pero la bandeja y el push preguntaban lo mismo dos veces
        // seguidas y el segundo no aportaba nada.
        $accounts = $this->users->findByPartners($households);

        $this->recordInbox($accounts, $title, $body, $kind);
        $this->pushTo($households, $accounts, $title, $body, $kind);

        $sent = 0;
        foreach ($this->preferences->filter($households, NotificationTopic::PICKUP, NotificationTopic::CHANNEL_EMAIL) as $partner) {
            // getemail(), en minúscula, es el nombre real del getter heredado: PHP lo
            // resolvería igual escrito de otra forma, pero así un grep los encuentra todos.
            $address = $partner->getemail();
            if (null === $address || '' === trim($address)) {
                continue;
            }

            $message = (new TemplatedEmail())
                ->to($address)
                ->subject($subject)
                ->htmlTemplate($template)
                ->textTemplate(str_replace('.html.twig', '.txt.twig', $template))
                ->context($context + ['name' => $partner->getNameForDelivery()]);

            // Corre dentro de la petición que aplicó el cambio, que ya está guardado:
            // si el correo de un hogar falla, se anota y se sigue con el otro. Tumbar
            // aquí dejaría el cambio hecho y sin avisar a nadie.
            try {
                $this->mailer->send($message);
                ++$sent;
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('No se pudo avisar del cambio de la cesta compartida a {email}: {error}', [
                    'email' => $address,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Deja la copia en la bandeja. Quien no tiene cuenta de acceso no tiene bandeja donde
     * mirar, así que sencillamente no le llega ninguna fila.
     *
     * @param list<User>  $accounts Cuentas de los hogares.
     * @param string      $title    Titular.
     * @param string      $body     Detalle.
     * @param string      $kind     Constante Notification::KIND_*.
     */
    private function recordInbox(array $accounts, string $title, string $body, string $kind): void
    {
        if ([] === $accounts) {
            return;
        }

        $this->inbox->deliver($accounts, $kind, $title, $body);
    }

    /**
     * Manda el aviso al móvil de quien lo quiera y lo tenga activado.
     *
     * @param list<Partner> $households A quiénes avisar.
     * @param list<User>    $accounts   Sus cuentas, ya resueltas.
     * @param string        $title      Titular.
     * @param string        $body       Detalle.
     * @param string        $kind       Constante Notification::KIND_*.
     */
    private function pushTo(array $households, array $accounts, string $title, string $body, string $kind): void
    {
        $wanted = [];
        foreach ($this->preferences->filter($households, NotificationTopic::PICKUP, NotificationTopic::CHANNEL_PUSH) as $partner) {
            $wanted[(int) $partner->getId()] = true;
        }
        if ([] === $wanted) {
            return;
        }

        // Se filtran las cuentas ya traídas en vez de volver a pedirlas: un socix puede
        // tener más de una cuenta (histórico del dump), así que se mira su socix, no la
        // cuenta.
        $recipients = array_values(array_filter(
            $accounts,
            static fn (User $user): bool => isset($wanted[(int) $user->getPartner()?->getId()])
        ));
        if ([] === $recipients) {
            return;
        }

        $this->push->sendToMany(
            $recipients,
            $title,
            $body,
            $this->link->pathForKind($kind),
            'shared_basket_change',
        );
    }
}
