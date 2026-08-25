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
     * @return array{nodeIsBiweekly: bool, nodeName: ?string, nodeDatesLabel: ?string, cohortChoices: array<string, ?string>, excludeWeeklyShares: bool}
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
     * @return array{nodeIsBiweekly: bool, nodeName: ?string, nodeDatesLabel: ?string, cohortChoices: array<string, ?string>, excludeWeeklyShares: bool}
     */
    public function forNode(?Node $node): array
    {
        $nodeIsBiweekly = $node !== null && $node->getCadence() === Node::CADENCE_BIWEEKLY;

        $upcoming = $this->basketRepository->findBetweenDates(
            new \DateTime(),
            (new \DateTime())->modify('+12 weeks'),
        );

        $nodeDatesLabel = null;
        $cohortChoices = [];

        if ($nodeIsBiweekly) {
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
            'nodeName' => $node?->getName(),
            'nodeDatesLabel' => $nodeDatesLabel,
            // Si no hay baskets futuros aún, un único hueco para que el
            // ChoiceType no reviente con choices vacías.
            'cohortChoices' => $cohortChoices !== [] ? $cohortChoices : ['Sin asignar' => null],
            'excludeWeeklyShares' => $nodeIsBiweekly,
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
