<?php

namespace App\Service\Delivery;

use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centraliza "saltar / volver a recoger" una entrega: alterna el
 * WeeklyBasketStatus entre 1 (Recoge) y 2 (No la recoge) y registra el
 * PartnerEvent correspondiente (BASKET_SKIP / BASKET_UNSKIP).
 *
 * Saltar NO borra el WeeklyBasket — solo cambia su estado, que es lo que el
 * reparto y los PDF ya entienden. Esta es la semántica que el panel del socio
 * estrenó; se extrae aquí para que el calendario de recogida (admin) la
 * comparta sin duplicarla.
 *
 * NO decide políticas (deadline, R1 compartidos, shift activo): eso lo hace
 * cada caller según su contexto. Tampoco hace flush — lo hace el caller, junto
 * al resto de su unidad de trabajo.
 */
final class WeeklyBasketSkipper
{
    /** Estado "Recoge" (entrega normal). */
    public const STATUS_PICKS = 1;
    /** Estado "No la recoge" (saltada). */
    public const STATUS_SKIPPED = 2;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param WeeklyBasket $weeklyBasket
     * @return bool true si la entrega está marcada como saltada (estado 2).
     */
    public function isSkipped(WeeklyBasket $weeklyBasket): bool
    {
        return $weeklyBasket->getWeeklyBasketStatus()?->getId() === self::STATUS_SKIPPED;
    }

    /**
     * Alterna entre "Recoge" (1) y "No la recoge" (2) y registra el evento.
     * Cualquier otro estado se deja intacto (caso defensivo) devolviendo null.
     *
     * @param WeeklyBasket $weeklyBasket Entrega a alternar (debe estar materializada).
     * @param string       $actor        Origen del evento ('gestor:<id>', 'partner:<id>', sistema).
     * @return bool|null true = ahora saltada; false = ahora recoge; null = estado especial, sin cambios.
     */
    public function toggle(WeeklyBasket $weeklyBasket, string $actor): ?bool
    {
        $currentId = $weeklyBasket->getWeeklyBasketStatus()?->getId();

        if ($currentId === self::STATUS_PICKS) {
            $this->apply($weeklyBasket, self::STATUS_SKIPPED, PartnerEvent::TYPE_BASKET_SKIP, $actor);

            return true;
        }

        if ($currentId === self::STATUS_SKIPPED) {
            $this->apply($weeklyBasket, self::STATUS_PICKS, PartnerEvent::TYPE_BASKET_UNSKIP, $actor);

            return false;
        }

        return null;
    }

    private function apply(WeeklyBasket $weeklyBasket, int $statusId, string $eventType, string $actor): void
    {
        $weeklyBasket->setWeeklyBasketStatus(
            $this->em->getRepository(WeeklyBasketStatus::class)->find($statusId),
        );

        $event = new PartnerEvent($weeklyBasket->getPartner(), $eventType);
        $event->setActor($actor);
        $this->em->persist($event);
    }
}
