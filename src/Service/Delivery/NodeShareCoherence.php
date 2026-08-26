<?php

namespace App\Service\Delivery;

use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasketGroup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mantiene coherentes las cestas de los socios con el punto donde recogen. Un
 * {@see Node} no es un dato suelto: define qué modalidades caben y qué entregas
 * del mes abre, y esos dos datos están COPIADOS en cada
 * {@see PartnerBasketShare} (basket_share y day_month_order). Que se separen no
 * da ningún error: la cesta simplemente deja de aparecer en el reparto — ver el
 * caso El Berrueco (2026-08-26).
 *
 * La regla la impone {@see PartnerBasketShare::validateAgainstNodeOffer} cuando
 * se edita una cesta. Este servicio cubre el lado contrario: los cambios que
 * mueven el PUNTO bajo los pies de cestas que ya existen. Son cuatro puertas y
 * todas acaban aquí:
 *
 *  - cambiar la cadencia de un punto      → {@see sharesThatNoLongerFit}
 *  - cambiar la semana de un punto mensual → {@see propagateMonthlyWeek}
 *  - enganchar un grupo entero a un punto  → {@see groupSharesThatDoNotFit}
 *  - mover un socio a otro grupo           → {@see partnerSharesThatDoNotFit}
 *
 * Sólo la semana del punto mensual se propaga sola: sus socios recogen la
 * semana que abra el punto y no hay decisión que tomar. En el resto la salida
 * es la lista de cestas incompatibles, para que quien manda el cambio lo pare y
 * corrija antes: a qué modalidad pasa cada socio es una decisión de
 * administración —con precio detrás— que el software no puede adivinar.
 */
class NodeShareCoherence
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketGenerator $generator,
    ) {
    }

    /**
     * Núcleo de la regla: de unas cestas dadas, cuáles no podría repartir el
     * punto. Puro — no toca BBDD — para poder preguntarlo sobre cambios que aún
     * no se han guardado.
     *
     * Dos formas de quedarse fuera, las mismas que impone
     * {@see PartnerBasketShare::validateAgainstNodeOffer}:
     *  - la modalidad no cabe en la cadencia del punto (una cesta semanal en uno
     *    que abre cada quince días);
     *  - es mensual y recoge una entrega que el punto no abre todos los meses
     *    (la "3ª entrega" no existe en un punto quincenal).
     *
     * En un punto MENSUAL la posición nunca descarta: la impone el punto y se
     * la estampa {@see propagateMonthlyWeek}. Pedir que se corrija a mano lo que
     * el propio cambio corrige solo sería ruido.
     *
     * @param Node|null              $node   Punto destino, o null si el socio se queda sin punto.
     * @param PartnerBasketShare[]   $shares Cestas a contrastar.
     * @return PartnerBasketShare[] Las que se quedarían fuera, vacío si ninguna.
     */
    public function sharesThatDoNotFit(?Node $node, array $shares): array
    {
        if ($node === null) {
            return []; // sin punto no hay nada que contrastar.
        }

        $allowed = $node->allowedShareIds();
        $offered = $node->offeredMonthOrders();

        $orphaned = [];
        foreach ($shares as $share) {
            $basketShare = $share->getBasketShare();
            if ($basketShare === null) {
                continue;
            }

            if ($allowed !== null && !in_array($basketShare->getId(), $allowed, true)) {
                $orphaned[] = $share;
                continue;
            }

            if (!$node->isMonthly()
                && $basketShare->isMonthly()
                && !in_array((int) $share->getDayMonthOrder(), $offered, true)
            ) {
                $orphaned[] = $share;
            }
        }

        return $orphaned;
    }

    /**
     * Cestas activas del punto que ya no podría repartir. Se evalúa contra la
     * cadencia que el objeto tenga AHORA, así que sirve para preguntar "si
     * guardo este cambio, ¿a quién dejo descolgado?" antes de hacer flush.
     *
     * @param Node $node Punto con la cadencia ya modificada (sin persistir).
     * @return PartnerBasketShare[]
     */
    public function sharesThatNoLongerFit(Node $node): array
    {
        return $this->sharesThatDoNotFit($node, $this->activeSharesOfNode($node));
    }

    /**
     * Cestas activas de los socios de un grupo que el punto destino no podría
     * repartir. Es la comprobación de "enganchar este grupo a este punto":
     * mueve de golpe a todos sus socios.
     *
     * @param WeeklyBasketGroup $group
     * @param Node|null         $node  Punto destino.
     * @return PartnerBasketShare[]
     */
    public function groupSharesThatDoNotFit(WeeklyBasketGroup $group, ?Node $node): array
    {
        return $this->sharesThatDoNotFit($node, $this->activeSharesOfGroup($group));
    }

    /**
     * Cestas activas de un socio que el punto destino no podría repartir. Es la
     * comprobación de "mover este socio a otro grupo de recogida".
     *
     * @param Partner   $partner
     * @param Node|null $node    Punto destino.
     * @return PartnerBasketShare[]
     */
    public function partnerSharesThatDoNotFit(Partner $partner, ?Node $node): array
    {
        $shares = $this->em->getRepository(PartnerBasketShare::class)
            ->findBy(['partner' => $partner, 'is_active' => true]);

        return $this->sharesThatDoNotFit($node, $shares);
    }

    /**
     * Cestas mensuales activas del punto: las que arrastra un cambio de semana.
     * Se consulta ANTES de guardar para poder avisar de a cuántos socios afecta.
     *
     * @param Node $node
     * @return PartnerBasketShare[]
     */
    public function monthlySharesOf(Node $node): array
    {
        return $this->em->createQuery(
            'SELECT pbs FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.partner p
             JOIN p.weekly_basket_group g
             WHERE g.node = :node
               AND pbs.is_active = 1
               AND pbs.basket_share IN (:monthly)
             ORDER BY p.name ASC'
        )
            ->setParameter('node', $node)
            ->setParameter('monthly', BasketShare::IDS_MONTHLY)
            ->getResult();
    }

    /**
     * Estampa la semana que abre el punto en todas sus cestas mensuales activas
     * y recoloca los listados YA generados de esos socios (misma cascada que la
     * corrección de errata y el cambio de modalidad, vía
     * {@see WeeklyBasketGenerator::reconcilePartnerFrom}).
     *
     * Hace flush: la llamada viene del guardado del punto, y dejar a medias la
     * propagación sería peor que no hacerla.
     *
     * @param Node $node Punto mensual con su semana ya actualizada.
     * @return PartnerBasketShare[] Cestas efectivamente reapuntadas.
     */
    public function propagateMonthlyWeek(Node $node): array
    {
        $week = $node->getMonthlyWeek();
        if (!$node->isMonthly() || $week === null) {
            return [];
        }

        $updated = [];
        foreach ($this->monthlySharesOf($node) as $share) {
            if ($share->getDayMonthOrder() === $week) {
                continue; // ya recogía esa entrega: nada que tocar ni que reconciliar.
            }
            $share->setDayMonthOrder($week);
            $updated[] = $share;
        }

        if ($updated === []) {
            return [];
        }

        $this->em->flush();

        $today = new \DateTime('today');
        foreach ($updated as $share) {
            $partner = $share->getPartner();
            if ($partner !== null) {
                $this->generator->reconcilePartnerFrom($partner, $today);
            }
        }

        return $updated;
    }

    /**
     * Cestas activas de los socios que recogen en el punto. Una query para todo
     * el punto: son datos de una pantalla de administración y no hay razón para
     * pedirlos socio a socio.
     *
     * @param Node $node
     * @return PartnerBasketShare[]
     */
    private function activeSharesOfNode(Node $node): array
    {
        return $this->em->createQuery(
            'SELECT pbs FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.partner p
             JOIN p.weekly_basket_group g
             WHERE g.node = :node
               AND pbs.is_active = 1
             ORDER BY p.name ASC'
        )
            ->setParameter('node', $node)
            ->getResult();
    }

    /**
     * Cestas activas de los socios de un grupo, en una query.
     *
     * @param WeeklyBasketGroup $group
     * @return PartnerBasketShare[]
     */
    private function activeSharesOfGroup(WeeklyBasketGroup $group): array
    {
        return $this->em->createQuery(
            'SELECT pbs FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.partner p
             WHERE p.weekly_basket_group = :group
               AND pbs.is_active = 1
             ORDER BY p.name ASC'
        )
            ->setParameter('group', $group)
            ->getResult();
    }
}
