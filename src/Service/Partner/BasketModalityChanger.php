<?php

namespace App\Service\Partner;

use App\Entity\PartnerBasketShare;
use App\Repository\PartnerBasketShareRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aplica un cambio de modalidad de cesta con histórico: cierra la PartnerBasketShare
 * vigente (end_date = víspera de la fecha efectiva), abre la nueva
 * (start_date = fecha efectiva) y las une semánticamente con un evento
 * BASKET_CHANGE.
 *
 * Centraliza la parte delicada que comparten el comando CLI
 * (app:change-basket-modality) y el formulario admin de cambio de modalidad:
 *  - localizar la PBS vigente en la VÍSPERA (no en la fecha efectiva, para no
 *    coger ya una PBS futura),
 *  - respetar el orden flush-antes-de-recordChange (el snapshot del evento
 *    llama a getId(): int sobre la PBS nueva, que ha de estar ya persistida),
 *  - partir el histórico en dos sin solapar fechas.
 *
 * NO decide la cascada de WeeklyBasket: tras aplicar el cambio, el caller debe
 * reconciliar las entregas ya materializadas con el patrón nuevo llamando a
 * {@see \App\Service\Delivery\WeeklyBasketGenerator::reconcilePartnerFrom}. Esa
 * pieza vive en el generador porque es quien sabe materializar la entrega de un
 * socio (añade donde ahora reparte, quita donde ya no), cosa que la generación
 * normal no hace sobre un listado ya generado.
 */
class BasketModalityChanger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerBasketShareRepository $shareRepository,
        private readonly PartnerShareEventRecorder $eventRecorder,
    ) {
    }

    /**
     * Cierra la PBS vigente del socio en la víspera de $effective y persiste
     * $new como su sustituta, uniéndolas con un evento BASKET_CHANGE.
     *
     * $new debe llegar ya poblada por el caller (partner, basket_share, huevos,
     * precios, day_month_order…); este método sólo fija sus fechas/estado y
     * orquesta el split + el evento.
     *
     * @param PartnerBasketShare $new PBS destino ya poblada.
     * @param \DateTimeInterface $effective Fecha efectiva del cambio.
     * @param string|null $actor Origen del evento (null = se resuelve vía Security).
     * @return PartnerBasketShare La PBS antigua, ya cerrada.
     * @throws \DomainException Si el socio no tiene cesta vigente en la víspera.
     */
    public function applyChange(PartnerBasketShare $new, \DateTimeInterface $effective, ?string $actor = null): PartnerBasketShare
    {
        $partner = $new->getPartner();
        $dayBefore = \DateTime::createFromInterface($effective)->modify('-1 day');

        $old = $this->shareRepository->findActiveForPartner($partner, $dayBefore);
        if ($old === null) {
            throw new \DomainException(sprintf(
                'El socio %d no tiene cesta vigente antes de %s; usa el alta de cesta, no el cambio de modalidad.',
                $partner->getId(),
                $effective->format('Y-m-d'),
            ));
        }

        $new->setStartDate(\DateTime::createFromInterface($effective));
        $new->setEndDate(null);
        $new->setIsActive(true);

        $old->setEndDate($dayBefore);
        $old->setIsActive(false);

        // Flush previo: recordChange hace snapshot vía getId() (: int estricto),
        // que no tolera la PBS nueva aún transitoria.
        $this->em->persist($new);
        $this->em->flush();

        $this->eventRecorder->recordChange($old, $new, $effective, $actor);
        $this->em->flush();

        return $old;
    }
}
