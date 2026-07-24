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

        // La cesta a sustituir es la suscripción ACTIVA en curso del socio, no "la
        // vigente en la víspera": si su cesta actual empieza en el futuro (cambio
        // programado a una fecha que aún no ha llegado), la víspera no la ve y se
        // cerraba por error una PBS antigua, dejando dos activas. Ver
        // {@see PartnerBasketShareRepository::findLatestActiveForPartner}.
        $old = $this->shareRepository->findLatestActiveForPartner($partner);
        if ($old === null) {
            throw new \DomainException(sprintf(
                'El socio %d no tiene cesta activa; usa el alta de cesta, no el cambio de modalidad.',
                $partner->getId(),
            ));
        }

        // ¿La cesta que se sustituye aún no había entrado en vigor? (start futuro
        // respecto a la fecha efectiva). Entonces no hay histórico que partir ni
        // entregas que conservar: se retira en vez de dejarla como tramo cerrado
        // fantasma (que además solaparía con la nueva).
        $neverStarted = $old->getStartDate() !== null
            && $old->getStartDate()->format('Y-m-d') >= \DateTime::createFromInterface($effective)->format('Y-m-d');

        $new->setStartDate(\DateTime::createFromInterface($effective));
        $new->setEndDate(null);
        $new->setIsActive(true);

        // Solo se cierra (end = víspera) la cesta que SÍ había entrado en vigor.
        // Si nunca empezó se va a eliminar abajo: cerrarla aquí escribiría una fila
        // con end_date < start_date en el flush previo (estado corrupto si algo
        // falla entre los dos flushes).
        if (!$neverStarted) {
            $old->setEndDate($dayBefore);
            // Solo desactivar (is_active=0) si el cierre YA es pasado. Si la cesta
            // vieja sigue vigente hasta una end_date FUTURA (cambio programado a
            // futuro), se deja is_active=1 y la cerrará finalizeExpiredShares cuando
            // expire, igual que una baja programada. Desactivarla YA la sacaría de
            // los finders de reparto —cesta (findBasketPartnersByTypeAndCity) y
            // huevo (findActiveSharesWithEggsForBasket), ambos exigen is_active=1—
            // durante su cola aún válida, y el socio se caería del listado (y en el
            // calendario aparecería un huevo suelto sin verdura, porque projectExtraEgg
            // resuelve el huevo con findActiveForPartner, que ignora is_active).
            if ($dayBefore->format('Y-m-d') < (new \DateTime('today'))->format('Y-m-d')) {
                $old->setIsActive(false);
            }
        }

        // Flush previo: recordChange hace snapshot vía getId() (: int estricto),
        // que no tolera la PBS nueva aún transitoria.
        $this->em->persist($new);
        $this->em->flush();

        // El snapshot del evento lee $old AQUÍ (con sus datos intactos), antes de
        // retirarla si nunca entró en vigor.
        $this->eventRecorder->recordChange($old, $new, $effective, $actor);
        if ($neverStarted) {
            $this->em->remove($old);
        }
        $this->em->flush();

        return $old;
    }
}
