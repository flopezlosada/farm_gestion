<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Repository\DeliveryExceptionRepository;
use App\Repository\WeeklyBasketRepository;

/**
 * Decide, PARA UN NODO Y UN BASKET, cómo se obtiene su reparto: leerlo de PIEDRA
 * (lo ya materializado en BBDD), DIBUJARLO al vuelo desde la proyección (sin
 * congelarlo), o dejarlo VACÍO. Es el corazón del rework de materialización tardía,
 * y la decisión es POR NODO, no global del basket.
 *
 * Por qué por nodo: un cierre que desplaza la cadencia de un nodo quincenal
 * (Cascorro/Midori) lo deja sin piedra en la semana nueva mientras OTRO nodo
 * (Torremocha, semanal) sí está materializado en ese mismo basket. Con una regla
 * global ("¿el basket tiene algún WeeklyBasket?") el nodo quincenal se leería de su
 * piedra vieja/vacía en vez de dibujarse, y el listado mostraría "no reparte" justo
 * tras un cierre. Ver vistas-reparto-dibujar-debt.
 *
 * Reglas (en orden):
 *  - El nodo no reparte ese día (cadencia/excepción) → {@see self::EMPTY}.
 *  - El nodo tiene reparto materializado en ese basket → {@see self::STONE} (la piedra
 *    manda aunque el día esté cancelado: lo que está congelado se respeta).
 *  - Cancelación global del basket → {@see self::EMPTY} (no se dibuja un día cerrado).
 *  - Basket pasado sin piedra → {@see self::EMPTY} (no se proyecta hacia atrás).
 *  - Resto → {@see self::DRAW}.
 */
class DeliveryModeResolver
{
    public const STONE = 'stone';
    public const DRAW = 'draw';
    public const EMPTY = 'empty';

    public function __construct(
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly WeeklyBasketRepository $weeklyBasketRepo,
        private readonly DeliveryExceptionRepository $deliveryExceptionRepo,
    ) {
    }

    /**
     * Modo de obtención del reparto de un nodo en un basket.
     *
     * @param Node   $node   Nodo del listado.
     * @param Basket $basket Semana (Basket-ciclo).
     * @return self::STONE|self::DRAW|self::EMPTY
     */
    public function mode(Node $node, Basket $basket): string
    {
        if (!$this->nodeDeliveryDate->deliversInBasket($basket, $node)) {
            return self::EMPTY;
        }
        if (count($this->weeklyBasketRepo->findForNodeAndBasket($node, $basket)) > 0) {
            return self::STONE;
        }
        $globalException = $this->deliveryExceptionRepo->findGlobalForBasket($basket);
        if ($globalException !== null && $globalException->isCancelled()) {
            return self::EMPTY;
        }
        if ($this->isPastBasket($basket)) {
            return self::EMPTY;
        }

        return self::DRAW;
    }

    /**
     * ¿El día de reparto ya pasó? Un Basket pasado sin reparto materializado se deja
     * vacío: nunca se proyecta hacia atrás (no tiene sentido dibujar un reparto que ya
     * debió ocurrir y del que no quedó constancia en piedra).
     *
     * @param Basket $basket Semana a evaluar.
     * @return bool Cierto si la fecha del basket es anterior a hoy.
     */
    private function isPastBasket(Basket $basket): bool
    {
        $date = $basket->getDate();
        if ($date === null) {
            return false;
        }

        return \DateTimeImmutable::createFromMutable($date)->setTime(0, 0)
            < new \DateTimeImmutable('today');
    }
}
