<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\BasketShare;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Estampa los WeeklyBasketItem (líneas de componente) de una entrega, reflejando
 * su composición real: verdura si la entrega lleva cesta, huevos si corresponde.
 *
 * Rediseño del calendario de recogida. La cantidad va en la unidad natural del
 * componente: verdura en CESTAS FÍSICAS (amount × peso de modalidad: compartida
 * ½, solo-huevos 0 → sin línea), huevos en docenas (EggAmount::getDozens()).
 *
 * La PRESENCIA de huevos por entrega aún se deriva del patrón (EggDeliveryResolver)
 * al estampar; el reparto v2 ya LEE estas líneas en lugar de re-derivar al pintar.
 */
class WeeklyBasketComposer
{
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
     * @param WeeklyBasket       $weeklyBasket       Entrega materializada.
     * @param PartnerBasketShare $share              Suscripción que originó la entrega.
     * @param Basket             $basket             Ciclo semanal de la entrega.
     * @param Basket|null        $eggReferenceBasket Basket contra el que decidir la
     *        PRESENCIA de huevos (cadencia). Por defecto el propio $basket. Para una
     *        entrega MOVIDA por un cambio puntual se pasa el basket ORIGEN: el huevo
     *        viaja con la cesta movida en lugar de re-derivarse por la cadencia del
     *        día destino (bug Etapa 2, caso Franco).
     */
    public function compose(WeeklyBasket $weeklyBasket, PartnerBasketShare $share, Basket $basket, ?Basket $eggReferenceBasket = null): void
    {
        $itemRepo = $this->em->getRepository(WeeklyBasketItem::class);
        if ($itemRepo->findBy(['weeklyBasket' => $weeklyBasket])) {
            return;
        }

        foreach ($this->computeItems($weeklyBasket, $share, $basket, $eggReferenceBasket) as $line) {
            $this->addItem($weeklyBasket, $line['component'], $line['amount']);
        }
    }

    /**
     * Calcula las líneas de componente de una entrega SIN persistir nada. Es la
     * lógica pura de composición (verdura + huevos) que comparten el camino de
     * escritura (compose) y la proyección de solo lectura del calendario de
     * recogida (modo dry de B': mostrar un mes futuro aún no materializado).
     *
     * El WeeklyBasket puede ser transitorio (no persistido): solo se leen su
     * amount y su basketShare. No toca la BBDD salvo lecturas (catálogo de
     * componentes y el resolver de huevos).
     *
     * @param WeeklyBasket       $weeklyBasket       Entrega (materializada o transitoria).
     * @param PartnerBasketShare $share              Suscripción que origina la entrega.
     * @param Basket             $basket             Ciclo semanal de la entrega.
     * @param Basket|null        $eggReferenceBasket Basket contra el que decidir la
     *        cadencia de huevos. Por defecto el propio $basket; se pasa el origen
     *        cuando la entrega viaja por un cambio puntual (ver compose()).
     * @return array<int, array{component: BasketComponent, amount: string}> Líneas a estampar.
     */
    public function computeItems(WeeklyBasket $weeklyBasket, PartnerBasketShare $share, Basket $basket, ?Basket $eggReferenceBasket = null): array
    {
        $componentRepo = $this->em->getRepository(BasketComponent::class);
        $vegetables = $componentRepo->find(BasketComponent::ID_VEGETABLES);
        $eggs = $componentRepo->find(BasketComponent::ID_EGGS);

        // BLINDAJE: si el catálogo de componentes no está sembrado (p. ej. código
        // desplegado sin la migración), NO estampamos nada y la generación sigue
        // intacta. Las líneas son una capa nueva que aún no lee nadie; jamás deben
        // poder tumbar el reparto. Etapa 1 es estrictamente aditiva.
        if ($vegetables === null || $eggs === null) {
            return [];
        }

        $share2 = $weeklyBasket->getBasketShare();
        $isOnlyEgg = $share2?->getId() === BasketShare::ID_ONLY_EGG;

        $lines = [];

        // Verdura: cestas FÍSICAS que reparte esta entrega = amount × peso de la
        // modalidad (compartida = ½, solo-huevos = 0). El peso vive en BasketShare
        // (getDeliveredBasketWeight) para no replicar la regla. Si da 0, no hay
        // línea de verdura — el "solo huevos" deja de ser un caso especial.
        $weight = $share2?->getDeliveredBasketWeight() ?? 1.0;
        $vegetableCestas = (float) $weeklyBasket->getAmount() * $weight;
        if ($vegetableCestas > 0) {
            $lines[] = ['component' => $vegetables, 'amount' => number_format($vegetableCestas, 2, '.', '')];
        }

        // Huevos: las entregas "solo huevos" por definición; las cestas, si el
        // resolver dice que esta entrega lleva huevo (refleja el comportamiento
        // actual, incluido su patrón-céntrico — se corrige en etapas siguientes).
        $carriesEggs = $isOnlyEgg || $this->eggResolver->delivers($share, $eggReferenceBasket ?? $basket);
        if ($carriesEggs && $share->getEggAmount() !== null) {
            $lines[] = ['component' => $eggs, 'amount' => number_format($share->getEggAmount()->getDozens(), 2, '.', '')];
        }

        return $lines;
    }

    /**
     * Lee la composición REAL de una entrega como líneas reutilizables por stamp().
     * Es la fuente de verdad de "qué lleva esta cesta" cuando ya está materializada
     * (refleja lo quitado/añadido a mano), frente a computeItems() que deriva del
     * patrón. La usan el cambio de día y el generador para que la composición viaje.
     *
     * @return array<int, array{component: BasketComponent, amount: string}>
     */
    public function copyLines(WeeklyBasket $weeklyBasket): array
    {
        $lines = [];
        foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $weeklyBasket]) as $item) {
            $lines[] = ['component' => $item->getBasketComponent(), 'amount' => $item->getAmount()];
        }

        return $lines;
    }

    /**
     * Estampa un conjunto EXACTO de líneas en una entrega, reemplazando las que
     * tenga (borra y pone). A diferencia de compose(), no deriva del patrón: copia
     * tal cual lo que se le pasa. Lo usa el cambio de día para que la composición
     * REAL de la cesta (con verdura/huevos quitados a mano) viaje con ella en vez de
     * re-derivarse del patrón en el destino.
     *
     * @param WeeklyBasket                                                  $weeklyBasket
     * @param array<int, array{component: BasketComponent, amount: string}> $lines
     */
    public function stamp(WeeklyBasket $weeklyBasket, array $lines): void
    {
        foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $weeklyBasket]) as $item) {
            $this->em->remove($item);
        }
        foreach ($lines as $line) {
            $this->addItem($weeklyBasket, $line['component'], $line['amount']);
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
