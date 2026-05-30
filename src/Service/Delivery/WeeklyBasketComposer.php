<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Estampa los WeeklyBasketItem (líneas de componente) de una entrega, reflejando
 * su composición real: verdura si la entrega lleva cesta, huevos si corresponde.
 *
 * Etapa 1 del rediseño del calendario de recogida: MATERIALIZA lo que hoy el
 * sistema deriva al vuelo (EggDeliveryResolver), SIN cambiar comportamiento —
 * todavía no lee estas líneas nadie. La cantidad va en la unidad natural del
 * componente: verdura en cestas, huevos en docenas (EggAmount::getDozens()).
 */
final class WeeklyBasketComposer
{
    /** BasketShare de "solo huevos": la entrega no lleva verdura. */
    private const SHARE_ONLY_EGG = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EggDeliveryResolver $eggResolver,
    ) {
    }

    /**
     * Crea las líneas de una entrega ya materializada. Idempotente: si la entrega
     * ya tiene líneas no hace nada (seguro para regeneración y backfill). No hace
     * flush — lo hace el llamante.
     *
     * @param WeeklyBasket       $weeklyBasket Entrega materializada.
     * @param PartnerBasketShare $share        Suscripción que originó la entrega.
     * @param Basket             $basket       Ciclo semanal de la entrega.
     */
    public function compose(WeeklyBasket $weeklyBasket, PartnerBasketShare $share, Basket $basket): void
    {
        $itemRepo = $this->em->getRepository(WeeklyBasketItem::class);
        if ($itemRepo->findBy(['weeklyBasket' => $weeklyBasket])) {
            return;
        }

        $componentRepo = $this->em->getRepository(BasketComponent::class);
        $vegetables = $componentRepo->find(BasketComponent::ID_VEGETABLES);
        $eggs = $componentRepo->find(BasketComponent::ID_EGGS);

        // BLINDAJE: si el catálogo de componentes no está sembrado (p. ej. código
        // desplegado sin la migración), NO estampamos nada y la generación sigue
        // intacta. Las líneas son una capa nueva que aún no lee nadie; jamás deben
        // poder tumbar el reparto. Etapa 1 es estrictamente aditiva.
        if ($vegetables === null || $eggs === null) {
            return;
        }

        $isOnlyEgg = $weeklyBasket->getBasketShare()?->getId() === self::SHARE_ONLY_EGG;

        // Verdura: toda entrega que no sea "solo huevos". Amount = nº de cestas.
        if (!$isOnlyEgg) {
            $this->addItem($weeklyBasket, $vegetables, number_format((float) $weeklyBasket->getAmount(), 2, '.', ''));
        }

        // Huevos: las entregas "solo huevos" por definición; las cestas, si el
        // resolver dice que esta entrega lleva huevo (refleja el comportamiento
        // actual, incluido su patrón-céntrico — se corrige en etapas siguientes).
        $carriesEggs = $isOnlyEgg || $this->eggResolver->delivers($share, $basket);
        if ($carriesEggs && $share->getEggAmount() !== null) {
            $this->addItem($weeklyBasket, $eggs, number_format($share->getEggAmount()->getDozens(), 2, '.', ''));
        }
    }

    /**
     * @param WeeklyBasket    $weeklyBasket
     * @param BasketComponent $component
     * @param string          $amount Decimal en la unidad natural del componente.
     */
    private function addItem(WeeklyBasket $weeklyBasket, BasketComponent $component, string $amount): void
    {
        $item = (new WeeklyBasketItem())
            ->setWeeklyBasket($weeklyBasket)
            ->setBasketComponent($component)
            ->setAmount($amount);
        $this->em->persist($item);
    }
}
