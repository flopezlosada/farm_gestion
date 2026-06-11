<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Re-materializa la PIEDRA de una semana: borra sus WeeklyBaskets y la vuelve
 * a generar desde el patrón vigente. Gracias al modelo de overrides (extras,
 * traslados de nodo y shifts viven en sus propias tablas), regenerar no pierde
 * información: la generación los re-aplica.
 *
 * Es la pieza que faltaba al registrar una excepción sobre una semana YA
 * generada (el agujero medido por los invariantes L8/L9/L11 — 737 violaciones
 * en el clon de la batería): regenerar honra la excepción vigente —
 * cancelación global → semana vacía; cancelación de nodo → ese nodo fuera;
 * traslado → fechas físicas recalculadas. Y una cancelación GLOBAL además
 * desplaza la alternancia A/B y la cadencia de nodos quincenales, así que las
 * semanas generadas POSTERIORES también se re-materializan.
 *
 * La pareja de {@see ClosureShiftReconciler} (que reconcilia los shifts):
 * él arregla los intents, esto arregla la piedra.
 */
class WeekRematerializer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketGenerator $generator,
    ) {
    }

    /**
     * Borra la piedra de la semana y la regenera desde el patrón vigente, en
     * una transacción (si la regeneración falla, la piedra vieja se conserva).
     *
     * @param Basket $basket Semana (ciclo) a re-materializar.
     * @return array{removed:int, created:int} WBs borrados y WBs resultantes.
     */
    public function rematerializeWeek(Basket $basket): array
    {
        return $this->em->wrapInTransaction(function () use ($basket): array {
            $removed = $this->dematerialize($basket);
            $this->generator->generateForBasket($basket);
            $created = $this->em->getRepository(WeeklyBasket::class)->count(['basket' => $basket]);

            return ['removed' => $removed, 'created' => $created];
        });
    }

    /**
     * Reconcilia la piedra tras CREAR, EDITAR o BORRAR una excepción que toca
     * la semana dada: re-materializa la semana si está dentro del horizonte ya
     * generado, y si el cambio desplaza la alternancia (una cancelación GLOBAL
     * entró o salió), re-materializa también todas las semanas generadas
     * posteriores. Las semanas fuera del horizonte no se tocan: siguen siendo
     * dibujo y se congelarán ya con la excepción contada.
     *
     * Llamar SIEMPRE después del flush de la excepción: la regeneración
     * consulta la tabla de excepciones y debe ver el estado nuevo.
     *
     * @param Basket $week                  Semana afectada por la excepción.
     * @param bool   $alternationDisplaced  True si una cancelación global se creó,
     *                                      se editó o se borró (la alternancia se mueve).
     * @return array{week:bool, downstream:int} Si se re-materializó la semana, y
     *                                          cuántas posteriores.
     */
    public function reconcileStone(Basket $week, bool $alternationDisplaced): array
    {
        $summary = ['week' => false, 'downstream' => 0];

        // Solo hay piedra que arreglar si la ventana generada llegó hasta aquí.
        if ($this->hasStoneAtOrAfter($week)) {
            $this->rematerializeWeek($week);
            $summary['week'] = true;
        }

        if ($alternationDisplaced) {
            foreach ($this->generatedBasketsAfter($week) as $later) {
                $this->rematerializeWeek($later);
                $summary['downstream']++;
            }
        }

        return $summary;
    }

    /**
     * Borra los WeeklyBaskets (e ítems) de la semana. No regenera: úsese vía
     * {@see rematerializeWeek()} para no dejar la semana a medias.
     *
     * @param Basket $basket
     * @return int WBs borrados.
     */
    private function dematerialize(Basket $basket): int
    {
        /** @var WeeklyBasket[] $weeklyBaskets */
        $weeklyBaskets = $this->em->getRepository(WeeklyBasket::class)->findBy(['basket' => $basket]);
        foreach ($weeklyBaskets as $wb) {
            foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $wb]) as $item) {
                $this->em->remove($item);
            }
            $this->em->remove($wb);
        }
        $this->em->flush();

        return count($weeklyBaskets);
    }

    /**
     * ¿La ventana generada alcanza esta semana? (la propia semana o alguna
     * posterior tienen piedra).
     */
    private function hasStoneAtOrAfter(Basket $week): bool
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(wb.id) FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             WHERE b.date >= :date'
        )->setParameter('date', $week->getDate())
         ->setMaxResults(1)
         ->getSingleScalarResult() > 0;
    }

    /**
     * Semanas con piedra posteriores a la dada, en orden.
     *
     * @return Basket[]
     */
    private function generatedBasketsAfter(Basket $week): array
    {
        return $this->em->createQuery(
            'SELECT DISTINCT b FROM ' . Basket::class . ' b
             JOIN ' . WeeklyBasket::class . ' wb WITH wb.basket = b
             WHERE b.date > :date
             ORDER BY b.date ASC'
        )->setParameter('date', $week->getDate())->getResult();
    }
}
