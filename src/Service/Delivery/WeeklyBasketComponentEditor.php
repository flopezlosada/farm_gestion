<?php

namespace App\Service\Delivery;

use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Edición "por contenido" de una entrega (B'): activa o desactiva un componente
 * (verdura / huevos) de un WeeklyBasket concreto, sin tocar el resto.
 *
 * El toggle alterna entre "la entrega lleva ese componente" y "no lo lleva":
 *  - Si ya hay línea para el componente → se quita (esta semana sin eso).
 *  - Si no la hay → se añade con la cantidad que dicta el patrón (la que
 *    calcularía el composer), reusando computeItems para no replicar la regla
 *    de cantidades. Si el patrón no incluye ese componente (p. ej. añadir
 *    huevos a quien no tiene huevos contratados), no se puede: eso sería un
 *    cambio del derecho (PBS), no una edición puntual de la entrega.
 *
 * NO hace flush — lo hace el caller.
 */
final class WeeklyBasketComponentEditor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketComposer $composer,
    ) {
    }

    /**
     * Alterna la presencia de un componente en una entrega materializada.
     *
     * @param WeeklyBasket $weeklyBasket Entrega (ya materializada).
     * @param int          $componentId  BasketComponent::ID_VEGETABLES | ID_EGGS.
     * @return string 'added' | 'removed' | 'unavailable' (el patrón no incluye ese componente).
     */
    public function toggle(WeeklyBasket $weeklyBasket, int $componentId): string
    {
        $itemRepo = $this->em->getRepository(WeeklyBasketItem::class);

        $existing = $itemRepo->findOneBy([
            'weeklyBasket' => $weeklyBasket,
            'basketComponent' => $componentId,
        ]);
        if ($existing !== null) {
            $this->em->remove($existing);

            return 'removed';
        }

        $share = $weeklyBasket->getPartnerBasketShare();
        $basket = $weeklyBasket->getBasket();
        if ($share === null || $basket === null) {
            return 'unavailable';
        }

        foreach ($this->composer->computeItems($weeklyBasket, $share, $basket) as $line) {
            if ($line['component']->getId() === $componentId) {
                $this->em->persist(
                    (new WeeklyBasketItem())
                        ->setWeeklyBasket($weeklyBasket)
                        ->setBasketComponent($line['component'])
                        ->setAmount($line['amount']),
                );

                return 'added';
            }
        }

        return 'unavailable';
    }
}
