<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\SharedBasketChangeRequest;
use App\Entity\WeeklyBasketGroup;
use App\Repository\SharedBasketChangeRequestRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Media entre los dos hogares de una cesta compartida: uno pide un cambio, el otro
 * lo acepta o lo rechaza, y sólo entonces se toca el reparto.
 *
 * LA PETICIÓN NO CAMBIA NADA. Lo único que ocurre al pedir es que se guarda la fila y
 * se avisa al otro hogar. El cambio se aplica —por {@see SharedPairDeliveryEditor}, la
 * única puerta— en el momento de aceptar, y VOLVIENDO A VALIDAR: entre pedir y
 * contestar pasan días, y en ese rato el plazo puede haber cerrado o el día destino
 * haberse ocupado. Validar sólo al pedir es cómo se acepta un cambio que ya no cabe.
 *
 * UNA PETICIÓN VIVA CADA VEZ entre los dos hogares. Dos acuerdos esperando sobre la
 * misma cesta son dos futuros distintos, y el segundo en aceptarse se encontraría el
 * reparto ya movido por el primero.
 *
 * EL CAMBIO DE MODALIDAD NO SE APLICA AL ACEPTAR, y es deliberado: lleva cuota detrás
 * y la ley L22 obliga a mover las dos suscripciones a la vez. Lo que esta vía aporta
 * ahí es lo que hoy se hace por teléfono — que los dos hogares hayan dicho que sí
 * antes de que administración toque nada— y el aviso a quien la va a aplicar.
 */
final class SharedBasketChangeMediator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SharedPairDeliveryEditor $editor,
        private readonly SharedBasketChangeRequestRepository $requests,
        private readonly SharedBasketChangeNotifier $notifier,
        private readonly UserRepository $users,
        private readonly PartnerBasketShareRepository $shares,
    ) {
    }

    /**
     * Pide al otro hogar cambiar de día el reparto de una semana.
     *
     * @param Partner     $requester Hogar que pide.
     * @param Basket      $from      Semana donde está la cesta.
     * @param Basket|null $to        Semana a la que se quiere mover.
     * @param string|null $note      Recado para el otro hogar.
     *
     * @return SharedBasketChangeRequest La petición guardada.
     *
     * @throws SharedPairException Si el cambio no es posible o ya hay otra esperando.
     */
    public function requestMove(Partner $requester, Basket $from, ?Basket $to, ?string $note = null): SharedBasketChangeRequest
    {
        // Se valida ANTES de molestar a nadie: pedir que alguien decida sobre un
        // cambio que ya se sabe imposible le hace perder el tiempo dos veces.
        $other = $this->editor->assertMoveAllowed($requester, $from, $to);
        $this->assertCanAnswer($other);
        $this->assertNothingPending($requester, $other);

        $request = new SharedBasketChangeRequest($requester, $other, SharedBasketChangeRequest::KIND_MOVE);
        $request->setBasket($from)->setToBasket($to)->setNote($note);

        return $this->save($request);
    }

    /**
     * Pide al otro hogar recoger una semana en otro punto.
     *
     * @param Partner           $requester Hogar que pide.
     * @param Basket            $basket    Semana afectada.
     * @param WeeklyBasketGroup $toGroup   Punto destino.
     * @param string|null       $note      Recado para el otro hogar.
     *
     * @return SharedBasketChangeRequest La petición guardada.
     *
     * @throws SharedPairException Si no son pareja o ya hay otra esperando.
     */
    public function requestRelocate(Partner $requester, Basket $basket, WeeklyBasketGroup $toGroup, ?string $note = null): SharedBasketChangeRequest
    {
        $other = $this->editor->counterpart($requester);
        $this->assertCanAnswer($other);
        $this->assertNothingPending($requester, $other);

        $request = new SharedBasketChangeRequest($requester, $other, SharedBasketChangeRequest::KIND_RELOCATE);
        $request->setBasket($basket)->setTargetGroup($toGroup)->setNote($note);

        return $this->save($request);
    }

    /**
     * Pide al otro hogar cambiar la modalidad o el turno de la cesta, de forma
     * permanente. Aceptarla no la aplica: la deja acordada para administración.
     *
     * @param Partner              $requester Hogar que pide.
     * @param array<string, mixed> $payload   Lo pedido (basket_share_id, delivery_group,
     *                                        day_month_order), tal cual se eligió.
     * @param string|null          $note      Recado para el otro hogar.
     *
     * @return SharedBasketChangeRequest La petición guardada.
     *
     * @throws SharedPairException Si no son pareja o ya hay otra esperando.
     */
    public function requestModality(Partner $requester, array $payload, ?string $note = null): SharedBasketChangeRequest
    {
        $other = $this->editor->counterpart($requester);
        $this->assertCanAnswer($other);
        $this->assertNothingPending($requester, $other);

        $request = new SharedBasketChangeRequest($requester, $other, SharedBasketChangeRequest::KIND_MODALITY);
        $request->setPayload($payload)->setNote($note);

        return $this->save($request);
    }

    /**
     * El otro hogar acepta. Aquí es donde el reparto cambia de verdad.
     *
     * @param SharedBasketChangeRequest $request Petición a aceptar.
     * @param Partner                   $decider Hogar que contesta (debe ser el destinatario).
     *
     * @throws SharedPairException Si no le toca decidir, si ya está resuelta, si el
     *                             plazo cerró o si el cambio ya no es posible.
     */
    public function accept(SharedBasketChangeRequest $request, Partner $decider): void
    {
        $this->assertDecider($request, $decider);

        $requester = $request->getRequester();
        if (null === $requester) {
            throw new SharedPairException('Esa petición está incompleta: avisa a administración.');
        }

        // Actor: quien PIDIÓ el cambio. En el histórico del socix lo que importa es de
        // quién salió; que el otro hogar dio su conformidad, y cuándo, queda en la
        // propia petición.
        $actor = 'partner:' . $requester->getId();

        switch ($request->getKind()) {
            case SharedBasketChangeRequest::KIND_MOVE:
                $from = $request->getBasket();
                $to = $request->getToBasket();
                if (null === $from || null === $to) {
                    throw new SharedPairException('Esa petición está incompleta: avisa a administración.');
                }

                // Revalida y aplica. Si el plazo cerró o el día destino se ocupó desde
                // que se pidió, salta aquí con su motivo y la petición sigue pendiente
                // para que quien contesta entienda qué pasó.
                //
                // El cambio y la respuesta se guardan JUNTOS. Por separado —el editor
                // confirma lo suyo y el `accept()` va en otro flush— basta que el proceso
                // muera en medio (un timeout del hosting, que los ha dado) para que la
                // cesta quede movida y la petición siga pendiente: el otro hogar podría
                // «rechazar» después un cambio ya aplicado, y se le diría que la cesta se
                // queda como estaba siendo mentira.
                $this->em->wrapInTransaction(function () use ($requester, $from, $to, $actor, $request): void {
                    $this->editor->move($requester, $from, $to, $actor);
                    $request->accept();
                });

                $this->notifier->moved([$requester, $decider], $from, $to, $requester->getNameForDelivery());

                return;

            case SharedBasketChangeRequest::KIND_RELOCATE:
                $basket = $request->getBasket();
                $group = $request->getTargetGroup();
                if (null === $basket || null === $group) {
                    throw new SharedPairException('Esa petición está incompleta: avisa a administración.');
                }

                // Misma unidad que el cambio, por lo mismo que en el caso de arriba.
                $this->em->wrapInTransaction(function () use ($requester, $basket, $group, $actor, $request): void {
                    $this->editor->relocate($requester, $basket, $group, $actor);
                    $request->accept();
                });

                $this->notifier->relocated([$requester, $decider], $basket, $group, $requester->getNameForDelivery());

                return;

            default:
                // Modalidad: queda acordada, no aplicada. Lo dice el aviso, para que
                // nadie cuente con un cambio que todavía tiene que pasar por
                // administración.
                $request->accept();
                $this->em->flush();

                $this->notifier->modalityAgreed($request);
        }
    }

    /**
     * El otro hogar dice que no. No se toca el reparto; se avisa a quien lo pidió.
     *
     * @param SharedBasketChangeRequest $request Petición a rechazar.
     * @param Partner                   $decider Hogar que contesta.
     *
     * @throws SharedPairException Si no le toca decidir o ya está resuelta.
     */
    public function reject(SharedBasketChangeRequest $request, Partner $decider): void
    {
        $this->assertDecider($request, $decider);

        $request->reject();
        $this->em->flush();

        $this->notifier->rejected($request);
    }

    /**
     * Quien la pidió se echa atrás antes de tener respuesta.
     *
     * No avisa a nadie: al otro hogar le desaparece de su bandeja de peticiones, y un
     * correo diciendo que ya no hace falta hacer nada es ruido sobre algo que nunca
     * llegó a pasar.
     *
     * @param SharedBasketChangeRequest $request   Petición a retirar.
     * @param Partner                   $requester Hogar que la pidió.
     *
     * @throws SharedPairException Si no era suya o ya está resuelta.
     */
    public function cancel(SharedBasketChangeRequest $request, Partner $requester): void
    {
        if ($request->getRequester()?->getId() !== $requester->getId()) {
            throw new SharedPairException('Esa petición no es tuya.');
        }
        if (!$request->isPending()) {
            throw new SharedPairException('Esa petición ya está resuelta.');
        }

        $request->cancel();
        $this->em->flush();
    }

    /**
     * Guarda la petición y avisa al otro hogar por sus canales.
     *
     * @param SharedBasketChangeRequest $request Petición nueva.
     *
     * @return SharedBasketChangeRequest La misma, ya persistida.
     */
    private function save(SharedBasketChangeRequest $request): SharedBasketChangeRequest
    {
        $this->em->persist($request);
        $this->em->flush();

        $this->notifier->requested($request);

        return $request;
    }

    /**
     * Que quien contesta sea el destinatario y que la petición siga viva.
     *
     * @param SharedBasketChangeRequest $request Petición.
     * @param Partner                   $decider Quien intenta contestar.
     *
     * @throws SharedPairException Si no le toca, si ya está resuelta o si el plazo cerró.
     */
    private function assertDecider(SharedBasketChangeRequest $request, Partner $decider): void
    {
        if ($request->getCounterpart()?->getId() !== $decider->getId()) {
            throw new SharedPairException('Esa petición no te toca contestarla a ti.');
        }
        if (!$request->isPending()) {
            throw new SharedPairException('Esa petición ya está resuelta.');
        }
        if (!$request->isActionable()) {
            throw new SharedPairException('Ese reparto ya pasó: la petición se quedó sin efecto.');
        }
    }

    /**
     * Que el otro hogar pueda contestar de verdad, o sea que tenga cuenta de acceso.
     *
     * NO ES UN DETALLE: hoy, de las catorce personas que comparten cesta en el padrón,
     * dos tienen cuenta, y en ninguna pareja la tienen las dos. Sin esta comprobación, lo
     * normal sería mandar una petición que nadie va a poder contestar nunca —y que además
     * bloquearía las siguientes— con un correo que dice «entra en tu panel» a quien no
     * tiene panel. Es mejor decirlo al pedirlo y mandar a esa persona a administración,
     * que es quien sí puede aplicar el cambio hablando con los dos.
     *
     * Se cae solo según la gente vaya teniendo acceso: el día que el otro hogar entre, la
     * comprobación deja de saltar sin tocar nada.
     *
     * @param Partner $counterpart Hogar que tendría que contestar.
     *
     * @throws SharedPairException Si no tiene ninguna cuenta con la que entrar.
     */
    private function assertCanAnswer(Partner $counterpart): void
    {
        if ([] === $this->users->findByPartners([$counterpart])) {
            throw new SharedPairException(sprintf(
                '%s todavía no tiene acceso a la web, así que no podría contestarte. Escribe a administración y lo cambian ellos.',
                $counterpart->getNameForDelivery(),
            ));
        }
    }

    /**
     * Que no haya ya otra petición esperando entre estos dos hogares.
     *
     * @param Partner $one   Un hogar.
     * @param Partner $other El otro.
     *
     * @throws SharedPairException Si hay una viva.
     */
    private function assertNothingPending(Partner $one, Partner $other): void
    {
        // Un cambio de MODALIDAD ya acordado y aún sin aplicar cuenta como vivo, aunque su
        // estado diga "aceptada": aceptar no lo aplica —lo aplica administración—, así que
        // hasta entonces la pareja tiene un acuerdo en curso. Dejar pedir otra cosa encima
        // sería acumular acuerdos que administración tendría que desenredar.
        $currentId = $this->shares->findActiveForPartner($one, new \DateTime('today'))?->getBasketShare()?->getId();
        if ([] !== $this->requests->findAgreedModalityPending($one, $other, $currentId)) {
            throw new SharedPairException('Ya tenéis acordado un cambio de modalidad y administración todavía no lo ha aplicado. Esperad a que esté hecho.');
        }

        foreach ($this->requests->findPendingBetween($one, $other) as $pending) {
            // Una pendiente cuyo reparto ya pasó no estorba: nadie va a contestarla y
            // bloquear por ella dejaría a la pareja sin poder pedir nada nunca más.
            if ($pending->isActionable()) {
                throw new SharedPairException('Ya hay una petición esperando respuesta sobre vuestra cesta. Resolvedla antes de pedir otra cosa.');
            }
        }
    }
}
