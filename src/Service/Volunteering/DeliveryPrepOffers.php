<?php

namespace App\Service\Volunteering;

use App\Entity\Node;
use App\Entity\VolunteerOffer;
use App\Repository\VolunteerOfferRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mantiene la convocatoria de montaje de cada punto de recogida al día con lo
 * que ese punto declara.
 *
 * POR QUÉ EXISTE. Las tareas de montar las cestas se creaban a mano, una por
 * semana y por punto: cincuenta y dos al año en Torremocha, y la que se
 * olvidara dejaba a la gente sin saber que hacía falta ayuda. El punto ya sabe
 * cuándo abre —lo dice el calendario de reparto— y ahora dice también si monta
 * con voluntariado, a qué hora y cuánta gente. Con eso no hace falta que nadie
 * se acuerde.
 *
 * EL PUNTO GOBIERNA LA RECETA, no la tarea. Hora, desfase y plazas se reescriben
 * desde el punto en cada pasada, así que cambiar la hora del montaje se hace
 * allí y llega a todos los turnos futuros. Si la tarea pudiera desviarse,
 * {@see Node::deliveryPrepWindowFor()} y los turnos reales dejarían de coincidir
 * y la tarjeta «quién prepara tu cesta» buscaría donde no hay nadie: sin error y
 * sin aviso, que es la peor clase de fallo.
 *
 * LO EDITORIAL ES DE QUIEN GESTIONA y no se toca nunca: título, explicación,
 * áreas, coordinación y los minutos que se reconocen. Por eso la convocatoria
 * NACE EN BORRADOR: publicarla sola convocaría a gente a una tarea sin explicar
 * y sin área, y sin área no le llega el aviso a nadie porque los avisos van por
 * área. Un gesto la primera vez; ninguno las cincuenta y dos semanas siguientes.
 *
 * UN TURNO SUELTO SÍ SE MUEVE A MANO: {@see \App\Entity\VolunteerShift::isManual()}
 * le gana a la receta y {@see ShiftGenerator::sync()} lo respeta. Cambiar la hora
 * de un viernes con asamblea no es lo mismo que cambiarla siempre.
 */
class DeliveryPrepOffers
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VolunteerOfferRepository $offers,
        private readonly ShiftGenerator $shifts,
    ) {
    }

    /**
     * Pone la convocatoria de este punto de acuerdo con lo que el punto dice.
     *
     * NO HACE FLUSH: quien orquesta decide la transacción, igual que
     * {@see ShiftGenerator}. Así crear la convocatoria y sus turnos es un solo
     * acto y no quedan convocatorias sin turnos si algo falla en medio.
     *
     * Devuelve lo mismo que {@see ShiftGenerator::sync()} más la convocatoria,
     * porque a sus dos consumidores les hace falta distinto: la pantalla del
     * punto necesita saber si quedó en borrador para decirlo, y el cron necesita
     * cuántos turnos ha abierto para contarlos.
     *
     * @param Node                    $node el punto de recogida
     * @param \DateTimeInterface|null $now  momento de referencia; por defecto, ahora
     *
     * @return array{offer: VolunteerOffer|null, created: list<\App\Entity\VolunteerShift>, removed: int, kept: list<\App\Entity\VolunteerShift>}
     */
    public function sync(Node $node, ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTime();
        $offer = $this->offers->findDeliveryPrepOffer($node);

        // El punto deja de montar con voluntariado: la convocatoria se PAUSA, no
        // se borra. Puede tener gente apuntada, y guarda quién montó qué semana:
        // eso es historia de la asociación, no configuración.
        if (!$node->isDeliveryPrep()) {
            $offer?->setStatus(VolunteerOffer::STATUS_PAUSED);

            return $this->untouched($offer);
        }

        $slot = $node->deliveryPrepTimeSlot();
        if (null === $slot) {
            // Marcado sin hora. No debería llegar aquí —lo impide
            // Node::validateDeliveryPrep()— y aun así no se inventa ninguna: sin
            // hora la receta no dicta turnos, y la convocatoria nacería muda.
            return $this->untouched($offer);
        }

        if (null === $offer) {
            $offer = (new VolunteerOffer())
                ->setTitle(sprintf('Montaje de cestas · %s', $node->getName()))
                ->setStatus(VolunteerOffer::STATUS_DRAFT)
                ->setDeliveryPrep(true);

            // El día de arranque de la receta, que es obligatorio y cuya
            // ausencia es SILENCIOSA: sin él ShiftGenerator::window() devuelve
            // null y el sync no crea nada, no retira nada y no se queja. Una
            // convocatoria sin turnos y sin pista de por qué.
            $offer->setRepeatFrom(\DateTimeImmutable::createFromInterface($now)->setTime(0, 0));

            $this->em->persist($offer);
        }

        // Lo que gobierna el punto, reescrito SIEMPRE —también en una
        // convocatoria que ya existía—, porque es la única forma de que cambiar
        // la hora en el punto llegue a los turnos futuros.
        //
        // El nodo va aquí y no en la rama de creación por lo mismo que
        // `repeatFrom`: sin nodo, la cadencia del reparto no tiene calendario al
        // que preguntar y devuelve lista vacía en silencio.
        $offer
            ->setNode($node)
            ->setRepeatType(VolunteerOffer::REPEAT_DELIVERY)
            ->setRepeatOffsetDays($node->getDeliveryPrepDayOffset())
            ->setRepeatTimes([$slot])
            ->setSlots($node->getDeliveryPrepSlots())
            // Sin fecha final: el montaje no se acaba en diciembre. Los turnos
            // los va abriendo el horizonte rodante del generador.
            ->setRepeatUntil(null);

        // sync() y no generate(): con generate() sólo se añadirían turnos, así
        // que cambiar la hora del montaje dejaría conviviendo los de las cinco y
        // los de las siete.
        return ['offer' => $offer] + $this->shifts->sync($offer, $now);
    }

    /**
     * El resultado de no haber tocado ningún turno.
     *
     * @param VolunteerOffer|null $offer la convocatoria, si la hay
     *
     * @return array{offer: VolunteerOffer|null, created: list<\App\Entity\VolunteerShift>, removed: int, kept: list<\App\Entity\VolunteerShift>}
     */
    private function untouched(?VolunteerOffer $offer): array
    {
        return ['offer' => $offer, 'created' => [], 'removed' => 0, 'kept' => []];
    }
}
