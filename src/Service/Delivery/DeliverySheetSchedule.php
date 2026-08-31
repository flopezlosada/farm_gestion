<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Repository\BasketRepository;
use App\Repository\NodeRepository;
use App\Service\AppSettings;
use Symfony\Component\Clock\ClockInterface;

/**
 * Decide de qué repartos toca mandar ya el listado: aquéllos cuyo plazo de
 * cambios ha cerrado y cuyo reparto todavía no ha ocurrido.
 *
 * CADA NODO A SU HORA. El plazo cierra la noche anterior al reparto de cada nodo
 * ({@see DeliveryDeadline}) y los nodos no reparten el mismo día: Madrid recoge
 * el miércoles, así que cierra el martes por la noche; la Sierra recoge el
 * viernes y cierra el jueves. Preguntar "¿es viernes?" serviría a un nodo y
 * llegaría tarde al otro — es el mismo error Sierra-céntrico que hizo que a
 * socixs de Madrid les llegara "recoge tu cesta el viernes en Torremocha".
 *
 * Vive fuera del comando ({@see \App\Command\SendDeliverySheetsCommand}) porque
 * es la regla de negocio de la tarea, y encerrada en él sólo se podría probar
 * esperando a que fuera martes por la noche de verdad.
 */
class DeliverySheetSchedule
{
    /**
     * Margen con el que se buscan ciclos alrededor de la ventana de plazos. La
     * fecha física de un nodo no es la del ciclo (Madrid reparte el miércoles
     * anterior, un festivo puede adelantar al jueves), así que mirar sólo los
     * ciclos de la ventana dejaría fuera repartos que sí entran. Una semana a cada
     * lado cubre cualquier desplazamiento real y son un puñado de filas más.
     */
    private const CYCLE_MARGIN_DAYS = 7;

    public function __construct(
        private readonly BasketRepository $basketRepository,
        private readonly NodeRepository $nodeRepository,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly DeliveryDeadline $deadline,
        private readonly DeliveryModeResolver $modeResolver,
        private readonly AppSettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Repartos cuyo listado toca mandar ahora mismo.
     *
     * Dirigida por ESTADO y no por el instante del disparo: pregunta qué repartos
     * tienen el plazo ya cerrado y siguen por delante, así que un disparo a
     * deshora da el mismo resultado y una pasada perdida la recupera la siguiente
     * mientras el reparto no haya pasado. Pasado el reparto deja de devolverlo: un
     * listado que llega después no le sirve a nadie.
     *
     * Con $target se pide una fecha física concreta y se ignora el plazo, que es
     * lo que permite reenviar un listado o probar el correo sin esperar al cierre.
     *
     * Cada reparto viene con `frozen`: si su semana está CONGELADA o todavía se
     * dibujaría al vuelo. Importa porque lo que sale tras el cierre se anuncia
     * como definitivo, y un dibujo todavía se puede mover; quien lo consume
     * decide, pero tiene que saberlo.
     *
     * @param \DateTimeImmutable|null $target Fecha física forzada, o null para el camino normal.
     * @return list<array{node: Node, basket: Basket, physical_date: \DateTimeImmutable, deadline: \DateTimeImmutable, frozen: bool}>
     */
    public function pending(?\DateTimeImmutable $target = null): array
    {
        $now = $this->clock->now();
        $today = $now->setTime(0, 0);

        $pending = [];
        foreach ($this->nodeRepository->findBy([], ['name' => 'ASC']) as $node) {
            foreach ($this->cyclesInWindow($target, $today) as $basket) {
                // null = ese nodo no reparte en este ciclo: o su cadencia no toca
                // (quincenal fuera de fase), o una excepción canceló el reparto. Si
                // una excepción lo trasladó, la fecha que vuelve es la trasladada.
                $physicalDate = $this->nodeDeliveryDate->physicalDateFor($basket, $node);
                if ($physicalDate === null) {
                    continue;
                }

                $deadline = $this->deadline->fromPhysicalDate($physicalDate);

                if (!$this->isDue($physicalDate, $deadline, $target, $today, $now)) {
                    continue;
                }

                $pending[] = [
                    'node' => $node,
                    'basket' => $basket,
                    'physical_date' => $physicalDate,
                    'deadline' => $deadline,
                    // Si la semana está CONGELADA o todavía se dibuja al vuelo. Se
                    // informa en vez de filtrar aquí para que cada tarea lo diga en
                    // su registro: descartar en silencio un nodo que ha cerrado su
                    // plazo se leería como "no había nada que hacer", que es
                    // exactamente lo contrario de lo que pasa.
                    'frozen' => $this->modeResolver->mode($node, $basket) === DeliveryModeResolver::STONE,
                ];
            }
        }

        return $pending;
    }

    /**
     * ¿Toca mandar el listado de este reparto?
     *
     * @param \DateTimeImmutable      $physicalDate Fecha física del reparto.
     * @param \DateTimeImmutable      $deadline     Cierre del plazo de cambios.
     * @param \DateTimeImmutable|null $target       Fecha física pedida a mano, si la hay.
     * @param \DateTimeImmutable      $today        Hoy a las 00:00.
     * @param \DateTimeImmutable      $now          Instante actual.
     */
    private function isDue(
        \DateTimeImmutable $physicalDate,
        \DateTimeImmutable $deadline,
        ?\DateTimeImmutable $target,
        \DateTimeImmutable $today,
        \DateTimeImmutable $now,
    ): bool {
        // Petición a mano de una fecha concreta: manda quien lo pide, no el plazo.
        if ($target !== null) {
            return $physicalDate->format('Y-m-d') === $target->format('Y-m-d');
        }

        // El reparto ya pasó: el listado llega tarde y no se manda.
        if ($physicalDate < $today) {
            return false;
        }

        // El plazo sigue abierto: el listado todavía puede cambiar.
        return $now >= $deadline;
    }

    /**
     * Ciclos candidatos. Un reparto sólo puede tener el plazo cerrado y estar aún
     * por delante si cae entre hoy y la antelación configurada del cierre; los
     * ciclos se buscan con margen porque la fecha física del nodo no coincide con
     * la del ciclo.
     *
     * @param \DateTimeImmutable|null $target Fecha física forzada, o null.
     * @param \DateTimeImmutable      $today  Hoy a las 00:00.
     * @return Basket[]
     */
    private function cyclesInWindow(?\DateTimeImmutable $target, \DateTimeImmutable $today): array
    {
        $daysBefore = $this->settings->getInt(AppSettings::DEADLINE_DAYS_BEFORE);

        $from = ($target ?? $today)->modify(sprintf('-%d days', self::CYCLE_MARGIN_DAYS));
        $to = ($target ?? $today->modify(sprintf('+%d days', $daysBefore)))
            ->modify(sprintf('+%d days', self::CYCLE_MARGIN_DAYS));

        return $this->basketRepository->findBetweenDates($from, $to);
    }
}
