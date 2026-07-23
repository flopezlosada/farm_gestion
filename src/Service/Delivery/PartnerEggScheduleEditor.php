<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Edición del calendario de HUEVOS de un socio, compartida por las dos pantallas que la
 * ofrecen: el calendario del gestor ({@see \App\Controller\PartnerDeliveryCalendarController})
 * y el panel del socio ({@see \App\Controller\PanelController}). Reúne las invariantes
 * ESTRUCTURALES en un solo sitio (DRY) para no volver a duplicarlas entre controladores.
 *
 * Alcance deliberado: SOLO el componente huevos. Los huevos no se comparten (cada hogar los
 * suyos, en su propio PartnerBasketShare), así que estas operaciones valen también en cestas
 * COMPARTIDAS, donde la cesta de verdura sí la marca la alternancia (R1) y no se toca aquí.
 *
 * Lo que NO vive aquí, por diseño: la autenticación, el CSRF, el feature-flag de autoservicio
 * y el DEADLINE del socio (jueves 23:59) — son propios de cada pantalla (el gestor no tiene
 * deadline de socio) y los aplica el controlador ANTES de llamar. Aquí solo la regla física
 * común "hasta el día anterior a la recogida".
 */
final class PartnerEggScheduleEditor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketGenerator $generator,
        private readonly DeliveryCalendarProjector $projector,
        private readonly DeliveryShiftApplier $applier,
    ) {
    }

    /**
     * Mueve SOLO los huevos de un socio de la semana $from a la semana $to. Reproduce las
     * mismas invariantes que tenía el camino del gestor (cesta activa, deadline día-anterior
     * sobre la fecha física, el nodo reparte el destino, destino futuro y sin huevos ya) y
     * delega la mutación en {@see DeliveryShiftApplier::moveComponent}, que es agnóstico al
     * socio (funciona igual en compartidas).
     *
     * @param Partner     $partner Socio dueño de los huevos.
     * @param Basket      $from    Semana origen (donde tiene los huevos ahora).
     * @param Basket|null $to      Semana destino elegida (null = no se eligió fecha válida).
     * @param string      $actor   Quién origina el cambio (ver PartnerEvent::$actor).
     * @throws EggScheduleException Con mensaje para el usuario si alguna invariante falla.
     */
    public function move(Partner $partner, Basket $from, ?Basket $to, string $actor): void
    {
        $share = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $from->getDate());
        if ($share === null) {
            throw new EggScheduleException('No hay una cesta activa esa semana.');
        }

        $today = new \DateTimeImmutable('today');

        // Deadline estructural: se mueve hasta el día ANTERIOR a la recogida (fecha física).
        $fromPhysical = $this->generator->projectShareDelivery($from, $share)['deliveryDate'] ?? $from->getDate();
        if ($fromPhysical <= $today) {
            throw new EggScheduleException('Esos huevos se recogen hoy o ya pasó: ya no se pueden mover.');
        }

        if ($to === null) {
            throw new EggScheduleException('Selecciona una fecha destino válida.');
        }

        $toProjection = $this->generator->projectShareDelivery($to, $share);
        if ($toProjection === null) {
            throw new EggScheduleException('El nodo no reparte ese día: elige otra fecha.');
        }
        $toPhysical = $toProjection['deliveryDate'] ?? $to->getDate();
        if ($toPhysical <= $today) {
            throw new EggScheduleException('No puedes mover los huevos a hoy ni a una fecha pasada.');
        }

        // Un hueco por componente: el destino no puede llevar YA huevos (duplicado). Un día
        // vacío o que solo lleva verdura es destino válido. Se usa la proyección del mes
        // (shift-aware) para ver también entregas previstas sin materializar.
        foreach ($this->projector->projectMonth($partner, (int) $to->getDate()->format('Y'), (int) $to->getDate()->format('n')) as $slot) {
            if ($slot['basket']->getId() !== $to->getId()) {
                continue;
            }
            foreach ($slot['items'] as $line) {
                if ($line['component']->getId() === BasketComponent::ID_EGGS) {
                    throw new EggScheduleException('Ya se recogen huevos ese día.');
                }
            }
            break;
        }

        $eggs = $this->em->getRepository(BasketComponent::class)->find(BasketComponent::ID_EGGS);
        if ($eggs === null) {
            throw new EggScheduleException('No se pudo resolver el componente huevos.');
        }

        $this->applier->moveComponent($partner, $eggs, $from, $to, $actor);
    }
}
