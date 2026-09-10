<?php

namespace App\Service\Delivery;

use App\Entity\WeeklyBasket;
use App\Repository\DeliveryExceptionRepository;

/**
 * Descarta las cestas cuyo reparto está CANCELADO por una excepción de
 * calendario.
 *
 * Hace falta porque una cancelación registrada DESPUÉS de materializar la
 * semana no limpia las WeeklyBasket ya creadas: se quedan vivas, con estado
 * "recoge" y con su fecha física en el día cancelado. Cualquiera que lea el
 * modelo materializado por fecha —el recordatorio para avisar, la cobertura
 * para contar a quién había que avisar— vería un reparto que no va a existir.
 *
 * Vive aparte y no dentro de quien lo usa porque lo necesitan dos: el que
 * envía y el que comprueba que se ha enviado. Si cada uno llevara su copia,
 * podrían dejar de estar de acuerdo sobre qué repartos cuentan, y entonces la
 * comprobación diría que falta gente por avisar cada vez que se cancela una
 * semana.
 */
class CancelledDeliveryFilter
{
    public function __construct(
        private readonly DeliveryExceptionRepository $exceptions,
    ) {
    }

    /**
     * Las cestas que siguen en pie, sin las de repartos cancelados.
     *
     * La precedencia entre la cancelación global y la de un nodo la resuelve
     * {@see DeliveryExceptionRepository::findForBasketAndNode()}. El resultado
     * se cachea por (reparto, nodo) durante la llamada: lxs destinatarixs de un
     * mismo día comparten ciclo y son pocos nodos, así que preguntar por cada
     * cesta serían decenas de consultas iguales.
     *
     * @param WeeklyBasket[] $baskets
     * @return WeeklyBasket[]
     */
    public function withoutCancelled(array $baskets): array
    {
        $cancelledByKey = [];

        return array_values(array_filter($baskets, function (WeeklyBasket $wb) use (&$cancelledByKey): bool {
            $basket = $wb->getBasket();
            if ($basket === null) {
                return true;
            }

            $node = $wb->getWeeklyBasketGroup()?->getNode();
            $key = $basket->getId() . ':' . ($node?->getId() ?? 'global');
            if (!array_key_exists($key, $cancelledByKey)) {
                $exception = $node !== null
                    ? $this->exceptions->findForBasketAndNode($basket, $node)
                    : $this->exceptions->findGlobalForBasket($basket);
                $cancelledByKey[$key] = $exception !== null && $exception->isCancelled();
            }

            return !$cancelledByKey[$key];
        }));
    }
}
