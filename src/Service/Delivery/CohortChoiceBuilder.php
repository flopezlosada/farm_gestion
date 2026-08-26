<?php

namespace App\Service\Delivery;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Repository\BasketRepository;

/**
 * Construye, para el formulario de cesta de un socio, las opciones del turno
 * A/B traducidas a las fechas físicas REALES de su nodo ("Viernes 19/06,
 * 26/06…") en vez del "Grupo A / Grupo B" pelado, que no informa al gestor.
 *
 * El turno A/B sólo se elige en nodos semanales (Torremocha), y ahí lo usan las
 * quincenales (para saber qué viernes recogen) y, opcionalmente, las mensuales
 * (para contar su orden sobre las entregas de ese turno en vez de sobre los
 * viernes del mes). En nodos quincenales (Cascorro, Midori) el turno lo fija el
 * propio punto y no se elige, así que devuelve sus fechas como información.
 *
 * Reutilizable por el alta de cesta, la corrección de errata y el cambio de
 * modalidad (antes la lógica vivía duplicada sólo en changeModality).
 */
class CohortChoiceBuilder
{
    /**
     * Etiqueta de "no anclado a ningún turno". La expone el JS del formulario,
     * que retira esta opción cuando la modalidad elegida es quincenal (allí el
     * turno es obligatorio).
     */
    public const NO_COHORT_LABEL = 'Sin turno · cuenta los viernes del mes';

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly BiweeklyCohortResolver $cohortResolver,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
    }

    /**
     * Calcula los datos del turno de viernes para el nodo de un socio.
     *
     * @return array{nodeIsBiweekly: bool, nodeIsMonthly: bool, nodeName: ?string, nodeDatesLabel: ?string, cohortChoices: array<string, ?string>, allowedShareIds: ?int[], offeredMonthOrders: ?int[], forcedMonthOrder: ?int}
     */
    public function forPartner(Partner $partner): array
    {
        return $this->forNode($partner->getWeeklyBasketGroup()?->getNode());
    }

    /**
     * Variante con nodo explícito, para cuando el grupo de recogida aún no
     * está fijado en la ficha: el alta de cesta de un socio sin grupo lo
     * elige en el propio formulario, y las opciones deben recalcularse para
     * el nodo del grupo elegido (no el del socio, que es NULL).
     *
     * `allowedShareIds` es la restricción de modalidades que impone el punto,
     * o null si no impone ninguna: en un punto quincenal no caben las cestas
     * de reparto semanal, y en uno mensual sólo caben las mensuales, porque
     * abre una única vez al mes. `offeredMonthOrders` son las posiciones de mes
     * que el punto sirve todos los meses (una cesta mensual no puede recoger en
     * una que su punto no abre). `forcedMonthOrder` es la semana que recogen
     * todos los socios de un punto mensual — allí no se elige, la fija el punto.
     * Los tres salen de {@see Node}, que es donde vive la regla.
     *
     * @return array{nodeIsBiweekly: bool, nodeIsMonthly: bool, nodeName: ?string, nodeDatesLabel: ?string, cohortChoices: array<string, ?string>, allowedShareIds: ?int[], offeredMonthOrders: ?int[], forcedMonthOrder: ?int}
     */
    public function forNode(?Node $node): array
    {
        $nodeIsBiweekly = $node !== null && $node->getCadence() === Node::CADENCE_BIWEEKLY;
        $nodeIsMonthly = $node !== null && $node->isMonthly();

        $upcoming = $this->basketRepository->findBetweenDates(
            new \DateTime(),
            (new \DateTime())->modify('+12 weeks'),
        );

        $nodeDatesLabel = null;
        $cohortChoices = [];

        if ($nodeIsBiweekly || $nodeIsMonthly) {
            // El punto tiene calendario propio: sus fechas se informan y el
            // turno A/B no se elige (no pinta nada en su cadencia).
            $nodeDates = [];
            foreach ($upcoming as $basket) {
                $date = $this->nodeDeliveryDate->operativeDateFor($basket, $node);
                if ($date !== null && count($nodeDates) < 4) {
                    $nodeDates[] = $date;
                }
            }
            $nodeDatesLabel = $nodeDates !== [] ? $this->labelFor($nodeDates) : null;
        } else {
            $byCohort = [
                PartnerBasketShare::DELIVERY_GROUP_A => [],
                PartnerBasketShare::DELIVERY_GROUP_B => [],
            ];
            foreach ($upcoming as $basket) {
                $cohort = $this->cohortResolver->cohortForBasket($basket);
                $date = $node !== null
                    ? $this->nodeDeliveryDate->operativeDateFor($basket, $node)
                    : $basket->getDate();
                if ($date !== null && count($byCohort[$cohort]) < 3) {
                    $byCohort[$cohort][] = $date;
                }
            }
            // "Sin turno" primero: es lo que corresponde a una MENSUAL que
            // cuenta los viernes del mes (el turno ahí es opcional, sólo la
            // ancla al calendario de su grupo). Para las quincenales el turno
            // es obligatorio y el JS del formulario retira esta opción.
            $cohortChoices[self::NO_COHORT_LABEL] = null;
            foreach ($byCohort as $cohort => $dates) {
                if ($dates !== []) {
                    $cohortChoices[$this->labelFor($dates)] = $cohort;
                }
            }
        }

        return [
            'nodeIsBiweekly' => $nodeIsBiweekly,
            'nodeIsMonthly' => $nodeIsMonthly,
            'nodeName' => $node?->getName(),
            'nodeDatesLabel' => $nodeDatesLabel,
            // Si no hay baskets futuros aún, un único hueco para que el
            // ChoiceType no reviente con choices vacías.
            'cohortChoices' => $cohortChoices !== [] ? $cohortChoices : ['Sin asignar' => null],
            // Qué ofrece el punto (modalidades y posiciones de mes): la regla
            // vive en Node, aquí sólo se transporta al formulario. La misma que
            // impone la validación de PartnerBasketShare.
            'allowedShareIds' => $node?->allowedShareIds(),
            'offeredMonthOrders' => $node?->offeredMonthOrders(),
            'forcedMonthOrder' => $nodeIsMonthly ? $node->getMonthlyWeek() : null,
        ];
    }

    /**
     * Etiqueta legible con el día de la semana real: "Viernes 19/06, 26/06…".
     *
     * @param \DateTimeInterface[] $dates
     */
    private function labelFor(array $dates): string
    {
        $day = Node::WEEKDAY_NAMES[(int) $dates[0]->format('N')] ?? '';

        return trim($day . ' ' . implode(', ', array_map(
            static fn (\DateTimeInterface $d): string => $d->format('d/m'),
            $dates,
        ))) . '…';
    }
}
