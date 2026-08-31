<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Repository\PartnerDeliveryShiftRepository;
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
        private readonly WeeklyBasketComponentEditor $componentEditor,
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
                    throw new EggScheduleException('Ese día ya recoge huevos. Si solo quieres no recoger los de esta semana, usa el interruptor de huevos.');
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

    /**
     * Alterna los HUEVOS de una semana: si se recogen, los quita ("no recojo huevos"); si no,
     * los devuelve. Reproduce el camino del toggle-por-componente del gestor (semana GENERADA:
     * retira/añade la línea del WeeklyBasket vía {@see WeeklyBasketComponentEditor}; semana SIN
     * generar: intent durable por componente que leen generador y proyector), pero SIN el gate
     * R1 — los huevos sí se editan en compartidas. El deadline de socio (jueves) NO va aquí: lo
     * aplica el controlador del panel antes de llamar, como con el resto de sus acciones.
     *
     * @param Partner $partner Socio dueño de los huevos.
     * @param Basket  $basket  Semana sobre la que actuar.
     * @param string  $actor   Quién origina el cambio (ver PartnerEvent::$actor).
     * @return string 'removed' = ya no recoge huevos · 'added' = vuelve a recogerlos ·
     *                'unavailable' = su cesta no contempla huevos esa semana (no se tocó nada).
     * @throws EggScheduleException Con mensaje para el usuario si una invariante estructural falla.
     */
    public function toggleEggs(Partner $partner, Basket $basket, string $actor): string
    {
        if ($basket->getDate() < new \DateTimeImmutable('today')) {
            throw new EggScheduleException('Esa entrega ya pasó: no se puede editar.');
        }

        /** @var PartnerDeliveryShiftRepository $shiftRepo */
        $shiftRepo = $this->em->getRepository(PartnerDeliveryShift::class);

        // Un cambio de DÍA de la entrega entera esa semana desincronizaría el toggle: se gestiona
        // primero desde los cambios de día. (Los huevos no tienen su propio "mover entera".)
        if ($shiftRepo->findOutgoing($partner, $basket) !== null) {
            throw new EggScheduleException('Hay un cambio de día activo esa semana: gestiónalo primero.');
        }

        $share = $this->em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());
        if ($share === null) {
            throw new EggScheduleException('No hay una cesta activa esa semana.');
        }

        $eggs = $this->em->getRepository(BasketComponent::class)->find(BasketComponent::ID_EGGS);
        if ($eggs === null) {
            throw new EggScheduleException('No se pudo resolver el componente huevos.');
        }

        // Semana GENERADA: se alterna la línea del WeeklyBasket materializado. Si la cesta vino
        // MOVIDA aquí (shift entrante), se materializa desde el origen (los huevos viajan); si no,
        // desde el patrón de la semana. Igual que resolveCalendarDelivery del gestor, sin R1.
        if ($this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket]) !== null) {
            $incoming = $shiftRepo->findIncoming($partner, $basket);
            $wb = $incoming !== null
                ? $this->applier->materializeShiftDestination($incoming)
                : $this->generator->materializeShareDelivery($basket, $share);
            if ($wb === null) {
                throw new EggScheduleException('El nodo no reparte esa semana.');
            }
            $result = $this->componentEditor->toggle($wb, BasketComponent::ID_EGGS);
            $this->em->flush();

            return $result;
        }

        // Semana SIN GENERAR: intent durable. Si ya hay un "quitar huevos", se cancela (vuelven);
        // si no, se crea — pero solo si el patrón da huevos esa semana (no se quita lo que no toca).
        foreach ($shiftRepo->findComponentIntentsFromBasket($partner, $basket) as $intent) {
            if ($intent->isSkip() && $intent->getComponent()?->getId() === BasketComponent::ID_EGGS) {
                $this->applier->cancelSkipIntent($intent, $actor);

                return 'added';
            }
        }

        $delivers = false;
        foreach ($this->projector->projectMonth($partner, (int) $basket->getDate()->format('Y'), (int) $basket->getDate()->format('n')) as $slot) {
            if ($slot['basket']->getId() !== $basket->getId()) {
                continue;
            }
            foreach ($slot['items'] as $line) {
                if ($line['component']->getId() === BasketComponent::ID_EGGS) {
                    $delivers = true;
                    break;
                }
            }
            break;
        }
        if (!$delivers) {
            return 'unavailable';
        }

        $this->applier->applySkipIntent($partner, $basket, $eggs, $actor);

        return 'removed';
    }
}
