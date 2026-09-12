<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasketGroup;
use App\Repository\PartnerDeliveryShiftRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cambios en una cesta COMPARTIDA, aplicados a los DOS hogares a la vez.
 *
 * Dos familias que comparten cesta reciben UNA cesta física que se parte en dos
 * mitades. De ahí que su día y su punto de recogida no sean datos de cada hogar
 * sino de la pareja: moverle el viernes a una y no a la otra deja media cesta sin
 * dueño el día viejo y media sin pareja el nuevo. Es justo lo que caza la ley L5
 * ({@see Invariant\SharedPairsTogetherInvariant}), y por eso hasta ahora la regla
 * R1 prohibía el cambio entero —a las dos pantallas, la del socix y la del gestor—
 * en vez de coordinarlo.
 *
 * ESTE SERVICIO ES LA ÚNICA PUERTA por la que una cesta compartida cambia de día o
 * de punto. Valida las DOS mitades antes de tocar nada y aplica las DOS dentro de
 * una transacción: si la segunda no se puede, la primera no llega a guardarse. Lo
 * contrario —validar sobre la marcha y aplicar según avanza— deja a la pareja
 * partida entre dos viernes, que es el estado que ninguna pantalla sabe dibujar.
 *
 * Lo que NO vive aquí, igual que en {@see PartnerEggScheduleEditor}: autenticación,
 * CSRF, feature-flag de autoservicio y el plazo propio del socix. Son de cada
 * pantalla y se aplican antes de llamar. Aquí sólo la regla física común.
 *
 * QUEDA FUERA "no recoger" (saltar la semana). En una compartida no está definido
 * qué pasa con la media cesta que se queda sin dueño: la decisión es de
 * administración y no del software, así que sigue bloqueado como hasta ahora.
 */
final class SharedPairDeliveryEditor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketGenerator $generator,
        private readonly DeliveryCalendarProjector $projector,
        private readonly DeliveryShiftApplier $applier,
        private readonly PickupRelocator $relocator,
    ) {
    }

    /**
     * El otro hogar de una cesta compartida.
     *
     * Exige el enlace MUTUO y que el otro hogar siga activo, que es lo que
     * comprueban las leyes L5 y L22 antes de emparejar a nadie: un
     * `share_partner_id` que apunta a quien ya no apunta de vuelta —o a quien se
     * dio de baja— no es una pareja, es un dato a medio limpiar, y coordinar un
     * cambio contra él movería la cesta de alguien que ya no la recibe.
     *
     * @param Partner $partner Uno de los dos hogares.
     *
     * @return Partner El otro hogar.
     *
     * @throws SharedPairException Si no comparte, si el enlace no es mutuo o si el
     *                             otro hogar está de baja.
     */
    public function counterpart(Partner $partner): Partner
    {
        $other = $partner->getSharePartner();
        if (null === $other) {
            throw new SharedPairException('Esta cesta no es compartida.');
        }

        if ($other->getSharePartner()?->getId() !== $partner->getId()) {
            throw new SharedPairException('El enlace con el otro hogar está incompleto: avisa a administración para que lo revise.');
        }

        if (true !== $other->getIsActive()) {
            throw new SharedPairException('El otro hogar de esta cesta está de baja: avisa a administración para que la revise.');
        }

        return $other;
    }

    /**
     * Mueve la cesta compartida de la semana $from a la semana $to, con sus dos
     * mitades.
     *
     * @param Partner     $partner Hogar desde el que se pide el cambio.
     * @param Basket      $from    Semana donde la cesta está ahora.
     * @param Basket|null $to      Semana destino (null = no se eligió una válida).
     * @param string      $actor   Quién origina el cambio (ver PartnerEvent::$actor).
     *
     * @return Partner El otro hogar, para poder nombrarlo en el mensaje y avisarle.
     *
     * @throws SharedPairException Con el motivo, si alguna de las dos mitades no puede.
     */
    public function move(Partner $partner, Basket $from, ?Basket $to, string $actor): Partner
    {
        $other = $this->assertMoveAllowed($partner, $from, $to);

        // Una sola unidad de trabajo: si la segunda mitad revienta, la primera no se
        // queda movida. El applier hace flush por su cuenta en cada llamada, así que
        // sin esto el fallo dejaría a la pareja partida entre dos viernes.
        $this->em->wrapInTransaction(function () use ($partner, $other, $from, $to, $actor): void {
            $this->applier->move($partner, $from, $to, $actor);
            $this->applier->move($other, $from, $to, $actor);
        });

        return $other;
    }

    /**
     * Comprueba que el movimiento es posible para las dos mitades, SIN aplicarlo.
     *
     * Lo usa la petición entre hogares antes de molestar a nadie: ofrecerle a alguien
     * que decida sobre un cambio que ya se sabe imposible es hacerle perder el tiempo
     * dos veces —al pedirlo y al contestarlo—. Y lo vuelve a usar {@see move()} al
     * aplicar, que es donde la comprobación de verdad cuenta: entre pedir y aceptar
     * pasan días, y el plazo o el día destino pueden haber cambiado.
     *
     * @param Partner     $partner Hogar desde el que se pide el cambio.
     * @param Basket      $from    Semana donde la cesta está ahora.
     * @param Basket|null $to      Semana destino.
     *
     * @return Partner El otro hogar.
     *
     * @throws SharedPairException Con el motivo, si alguna de las dos mitades no puede.
     */
    public function assertMoveAllowed(Partner $partner, Basket $from, ?Basket $to): Partner
    {
        $other = $this->counterpart($partner);

        if (null === $to) {
            throw new SharedPairException('Elige una fecha destino válida.');
        }
        if ($to->getId() === $from->getId()) {
            throw new SharedPairException('La cesta ya está en ese día.');
        }

        // Las DOS mitades: comparten nodo y modalidad (ley L22), así que normalmente
        // pasan o fallan juntas, pero cada hogar tiene su propia cesta vigente y sus
        // propios cambios puntuales, y ahí sí divergen —una puede llevar ya movida esa
        // semana por su cuenta—.
        foreach ([$partner, $other] as $household) {
            $this->assertCanMove($household, $from, $to);
        }

        return $other;
    }

    /**
     * Traslada la cesta compartida de una semana a otro punto de recogida, con sus
     * dos mitades.
     *
     * Las dos van al MISMO grupo aunque cada hogar tenga el suyo de casa (en el
     * padrón real los hay: Pablo recoge con La Cabrera y Vicente con Pedrezuela, los
     * dos en el nodo de Torremocha). La cesta física es una y acaba en un punto: el
     * destino no puede ser "el grupo de casa de cada cual" o se partiría en dos.
     *
     * @param Partner           $partner Hogar desde el que se pide el cambio.
     * @param Basket            $basket  Semana a trasladar.
     * @param WeeklyBasketGroup $toGroup Grupo de recogida destino.
     * @param string            $actor   Quién origina el cambio.
     *
     * @return Partner El otro hogar, para nombrarlo en el mensaje y avisarle.
     *
     * @throws SharedPairException Con el motivo, si alguna de las dos mitades no puede.
     */
    public function relocate(Partner $partner, Basket $basket, WeeklyBasketGroup $toGroup, string $actor): Partner
    {
        $other = $this->counterpart($partner);

        foreach ([$partner, $other] as $household) {
            $this->assertHasActiveShare($household, $basket);
            $this->assertDeadlineOpen($household, $basket);
        }

        // El relocator valida el resto (que el nodo destino reparta esa semana, que no
        // choque con un cambio de día) y lanza LogicException con su propio mensaje,
        // que ya está escrito para el usuario. Se traduce a la excepción de este
        // servicio para que las pantallas sólo tengan que capturar una.
        try {
            $this->em->wrapInTransaction(function () use ($partner, $other, $basket, $toGroup, $actor): void {
                $this->relocator->relocatePair($partner, $other, $basket, $toGroup, $actor);
            });
        } catch (\LogicException $e) {
            throw new SharedPairException($e->getMessage(), 0, $e);
        }

        return $other;
    }

    /**
     * Traslada de punto de recogida, coordinando a la pareja cuando la cesta es
     * compartida y dejando el camino de siempre cuando no lo es.
     *
     * Es lo que llaman las pantallas: las tres que ofrecen el traslado (el panel del
     * socix, la ficha del socio y el listado de reparto) atienden a las dos clases de
     * cesta, y repartir ese `if` por los controladores es cómo se cuela el traslado de
     * media cesta suelta por la puerta que nadie revisó.
     *
     * @param Partner           $partner Socix que recoge en otro punto esa semana.
     * @param Basket            $basket  Semana a trasladar.
     * @param WeeklyBasketGroup $toGroup Grupo de recogida destino.
     * @param string            $actor   Quién origina el cambio.
     *
     * @return Partner|null El otro hogar arrastrado por el cambio, o null si la cesta
     *                      no era compartida y sólo se movió quien lo pidió.
     *
     * @throws SharedPairException Si la cesta es compartida y el traslado no es posible.
     * @throws \LogicException     Si no lo es y el traslado no es posible (mensaje del
     *                             propio {@see PickupRelocator}, ya apto para pantalla).
     */
    public function relocateWithPair(Partner $partner, Basket $basket, WeeklyBasketGroup $toGroup, string $actor): ?Partner
    {
        if (null === $partner->getSharePartner()) {
            $this->relocator->relocate($partner, $basket, $toGroup, $actor);

            return null;
        }

        return $this->relocate($partner, $basket, $toGroup, $actor);
    }

    /**
     * Que este hogar pueda mover su mitad de $from a $to. Son las mismas invariantes
     * estructurales que aplica el calendario para una cesta no compartida, aquí en un
     * solo sitio porque hay que preguntarlas dos veces antes de mover nada.
     *
     * @param Partner $partner Hogar a comprobar.
     * @param Basket  $from    Semana origen.
     * @param Basket  $to      Semana destino.
     *
     * @throws SharedPairException Con el motivo concreto, ya nombrando al hogar que
     *                             no puede: quien pide el cambio necesita saber si el
     *                             problema es suyo o del otro.
     */
    private function assertCanMove(Partner $partner, Basket $from, Basket $to): void
    {
        $share = $this->assertHasActiveShare($partner, $from);
        $this->assertDeadlineOpen($partner, $from);

        $toProjection = $this->generator->projectShareDelivery($to, $share);
        if (null === $toProjection) {
            throw new SharedPairException('El nodo no reparte ese día: elige otra fecha.');
        }

        $toPhysical = $toProjection['deliveryDate'] ?? $to->getDate();
        if ($toPhysical <= new \DateTimeImmutable('today')) {
            throw new SharedPairException('No se puede mover la cesta a hoy ni a una fecha pasada.');
        }

        // Un hueco por día. El día de patrón de la propia cesta es la excepción:
        // volver ahí es deshacer el cambio, no ocupar un día lleno. El "traslado
        // sumando" (dos cestas el mismo día) NO se ofrece en compartidas: media cesta
        // duplicada no es una cesta, y qué se monta ese día es decisión del reparto.
        /** @var PartnerDeliveryShiftRepository $shiftRepo */
        $shiftRepo = $this->em->getRepository(PartnerDeliveryShift::class);
        $patternOrigin = $shiftRepo->findIncoming($partner, $from)?->getFromBasket() ?? $from;
        if ($to->getId() === $patternOrigin->getId()) {
            return;
        }

        $toDate = $to->getDate();
        foreach ($this->projector->projectMonth($partner, (int) $toDate->format('Y'), (int) $toDate->format('n')) as $slot) {
            if ($slot['basket']->getId() !== $to->getId()) {
                continue;
            }
            if (!empty($slot['items'])) {
                throw new SharedPairException(sprintf(
                    '%s ya tiene una entrega ese día: elige otra fecha.',
                    $partner->getNameForDelivery(),
                ));
            }
            break;
        }
    }

    /**
     * La cesta vigente de ese hogar esa semana.
     *
     * @param Partner $partner Hogar a comprobar.
     * @param Basket  $basket  Semana sobre la que se pregunta.
     *
     * @return PartnerBasketShare La cesta vigente.
     *
     * @throws SharedPairException Si ese hogar no tiene cesta esa semana.
     */
    private function assertHasActiveShare(Partner $partner, Basket $basket): PartnerBasketShare
    {
        $share = $this->em->getRepository(PartnerBasketShare::class)
            ->findActiveForPartner($partner, $basket->getDate());

        if (null === $share) {
            throw new SharedPairException(sprintf(
                '%s no tiene una cesta activa esa semana.',
                $partner->getNameForDelivery(),
            ));
        }

        return $share;
    }

    /**
     * Que esa semana siga siendo tocable: la cesta se cambia hasta el día ANTERIOR a
     * su recogida, medido sobre la fecha FÍSICA del nodo (no sobre el viernes del
     * ciclo, que en los nodos que reparten otro día no coinciden).
     *
     * @param Partner $partner Hogar a comprobar.
     * @param Basket  $basket  Semana sobre la que se pregunta.
     *
     * @throws SharedPairException Si ya se recoge hoy o ya pasó.
     */
    private function assertDeadlineOpen(Partner $partner, Basket $basket): void
    {
        $share = $this->assertHasActiveShare($partner, $basket);
        $physical = $this->generator->projectShareDelivery($basket, $share)['deliveryDate'] ?? $basket->getDate();

        if ($physical <= new \DateTimeImmutable('today')) {
            throw new SharedPairException('Esa cesta se recoge hoy o ya pasó: ya no se puede cambiar.');
        }
    }
}
