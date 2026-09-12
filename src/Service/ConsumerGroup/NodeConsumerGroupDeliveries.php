<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\Node;
use App\Repository\ConsumerGroupOrderRepository;
use App\Service\AppSettings;

/**
 * Los pedidos del grupo de consumo que se entregan en un nodo el día del
 * reparto, para la hoja de quien reparte.
 *
 * EL AGUJERO QUE TAPA: la comisión podía llevar el pedido entero en la app
 * —abrirlo, cobrarlo, confirmarlo— y el viernes, en el punto de recogida, el
 * papel sólo hablaba de cestas. O sea que el reparto seguía necesitando una
 * lista aparte, que es exactamente lo que el módulo venía a quitar.
 *
 * SE CASA POR SEMANA, NO POR DÍA EXACTO. La comisión pone una fecha de entrega
 * (normalmente el viernes), pero cada nodo reparte su día: un nodo que reparte
 * el jueves no habría visto nunca un pedido fechado el viernes, y como ese día
 * no tiene reparto, el pedido no habría aparecido en ninguna hoja. La promesa
 * es «se entrega con la cesta de esa semana», así que la semana es la unidad
 * correcta.
 *
 * DEUDA CONOCIDA: el calendario del socix
 * ({@see PartnerConsumerGroupDeliveries}) sí casa por día exacto. Con el nodo
 * repartiendo otro día, ahí se pinta en la casilla de la fecha de la ronda y
 * aquí en la hoja del día del nodo. Converger las dos en la regla de semana
 * cuando se toque el calendario.
 *
 * El módulo va detrás de un feature-flag y esto corre también desde la tarea que
 * manda las hojas por correo, donde no hay usuario contra el que votar: por eso
 * se lee el ajuste directamente y no vía FeatureVoter.
 */
class NodeConsumerGroupDeliveries
{
    public function __construct(
        private readonly ConsumerGroupOrderRepository $orders,
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * Pedidos a entregar en ese nodo la semana de la fecha dada.
     *
     * @param Node                    $node         el punto de recogida
     * @param \DateTimeInterface|null $physicalDate día en que ese nodo reparte
     *
     * @return list<array{partner: string, lines: list<array{quantity: string, unit: string, name: string}>}>
     *         una entrada por socia, con lo que se le entrega
     */
    public function forNodeAndDate(Node $node, ?\DateTimeInterface $physicalDate): array
    {
        if ($physicalDate === null || !$this->settings->getBool(AppSettings::FEATURE_GRUPO_CONSUMO)) {
            return [];
        }

        $monday = (clone \DateTime::createFromInterface($physicalDate))->modify('monday this week')->setTime(0, 0);
        $sunday = (clone $monday)->modify('+6 days')->setTime(23, 59, 59);

        $out = [];
        foreach ($this->orders->findDeliverableForNodeBetween($node, $monday, $sunday) as $order) {
            $lines = $this->linesOf($order);
            if ([] === $lines) {
                continue;
            }

            $out[] = [
                'partner' => (string) $order->getPartner(),
                'lines' => $lines,
            ];
        }

        return $out;
    }

    /**
     * Las líneas con cantidad de un pedido, en formato plano para la hoja.
     *
     * @return list<array{quantity: string, unit: string, name: string}>
     */
    private function linesOf(ConsumerGroupOrder $order): array
    {
        $lines = [];
        foreach ($order->getLines() as $line) {
            if ((float) $line->getQuantity() <= 0 || $line->getRoundItem() === null) {
                continue;
            }

            $lines[] = [
                'quantity' => $line->getQuantity(),
                'unit' => $line->getRoundItem()->getUnit(),
                'name' => $line->getRoundItem()->getName(),
            ];
        }

        return $lines;
    }
}
