<?php

namespace App\Service\Delivery;

use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Repository\EmittedEffectRepository;
use App\Repository\UserRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;

/**
 * Responde, para un reparto concreto: de toda la gente que iba a recoger ese
 * día, ¿a quién se avisó, a quién no se podía avisar, y a quién se dejó de
 * avisar sin motivo?
 *
 * ESTA ÚLTIMA CIFRA ES LA QUE JUSTIFICA LA CLASE. Durante dos meses el
 * recordatorio dejó fuera a las modalidades compartidas y nadie se enteró
 * hasta que una socia escribió: la tarea corría en verde todos los días,
 * porque desde dentro hacía exactamente lo que le habían pedido. Un sistema de
 * avisos que sólo sabe lo que ha mandado no puede descubrir a quién no manda.
 *
 * POR ESO NO PREGUNTA COMO PREGUNTA EL RECORDATORIO. Éste elige destinatarixs
 * por una lista de modalidades; si la comprobación usara esa misma lista,
 * heredaría el error y confirmaría que todo está bien. Aquí se parte de TODO
 * el que recoge ese día ({@see WeeklyBasketRepository::findPickedByDeliveryDate()})
 * y el desglose se enseña entero, modalidad a modalidad, marcando cuáles
 * entran en el alcance del aviso. Una modalidad que debería recibirlo y sale
 * con cero avisados canta a la vista.
 *
 * Los avisados se leen del guardián de idempotencia y no de la bitácora de
 * envíos, porque aquél guarda la fecha del REPARTO y ésta la del envío.
 */
class PickupNoticeCoverage
{
    /**
     * Clases de efecto que cuentan como "avisadx". Las tres, porque las tres
     * son el mismo aviso por caminos distintos: quien recibió la copia en su
     * bandeja está avisadx aunque el correo esté apagado.
     */
    private const NOTICE_KINDS = [
        'pickup_reminder',
        'pickup_reminder_push',
        'pickup_reminder_inbox',
    ];

    public function __construct(
        private readonly WeeklyBasketRepository $weeklyBaskets,
        private readonly CancelledDeliveryFilter $cancelledFilter,
        private readonly EmittedEffectRepository $effects,
        private readonly UserRepository $users,
        private readonly NotificationPreferences $preferences,
    ) {
    }

    /**
     * Las modalidades a las que el recordatorio SÍ avisa. Misma definición que
     * usa el comando; aquí sirve para marcar filas, no para elegir a quién se
     * mira, que es la diferencia que hace útil la comprobación.
     *
     * @return int[]
     */
    public static function inScopeShares(): array
    {
        return [...BasketShare::IDS_BIWEEKLY, ...BasketShare::IDS_MONTHLY];
    }

    /**
     * Cobertura del reparto de una fecha física.
     *
     * @param \DateTimeInterface $date Día del reparto.
     * @return array{
     *     rows: list<array{share: string, in_scope: bool, picking: int, notified: int, unreachable: int, gap: int}>,
     *     totals: array{picking: int, notified: int, unreachable: int, gap: int},
     *     missing: list<Partner>
     * }
     */
    public function forDate(\DateTimeInterface $date): array
    {
        $baskets = $this->cancelledFilter->withoutCancelled(
            $this->weeklyBaskets->findPickedByDeliveryDate($date)
        );

        if ($baskets === []) {
            return [
                'rows' => [],
                'totals' => ['picking' => 0, 'notified' => 0, 'unreachable' => 0, 'gap' => 0],
                'missing' => [],
            ];
        }

        $notifiedIds = array_flip($this->effects->partnerIdsByKindsAndDate(self::NOTICE_KINDS, $date));
        $reachable = $this->reachablePartnerIds($baskets);
        $inScope = array_flip(self::inScopeShares());

        $rows = [];
        $missing = [];
        $totals = ['picking' => 0, 'notified' => 0, 'unreachable' => 0, 'gap' => 0];

        foreach ($baskets as $wb) {
            $partner = $wb->getPartner();
            $share = $wb->getBasketShare();
            if ($partner === null || $share === null) {
                continue;
            }

            $shareId = (int) $share->getId();
            $rows[$shareId] ??= [
                'share' => (string) $share->getName(),
                'in_scope' => isset($inScope[$shareId]),
                'picking' => 0,
                'notified' => 0,
                'unreachable' => 0,
                'gap' => 0,
            ];

            ++$rows[$shareId]['picking'];
            ++$totals['picking'];

            $partnerId = (int) $partner->getId();

            if (isset($notifiedIds[$partnerId])) {
                ++$rows[$shareId]['notified'];
                ++$totals['notified'];
                continue;
            }

            // Sin correo utilizable y sin cuenta de acceso no hay por dónde
            // avisar: no es un fallo del sistema, es una ficha incompleta, y
            // mezclarlo con los huecos de verdad haría que la cifra que importa
            // nunca bajase a cero y dejara de mirarse.
            if (!isset($reachable[$partnerId])) {
                ++$rows[$shareId]['unreachable'];
                ++$totals['unreachable'];
                continue;
            }

            // Fuera del alcance del recordatorio (las semanales) no hay hueco:
            // no recibir es lo correcto. Se cuentan aparte para que la fila
            // siga cuadrando.
            if (!isset($inScope[$shareId])) {
                continue;
            }

            ++$rows[$shareId]['gap'];
            ++$totals['gap'];
            $missing[] = $partner;
        }

        // Las que sí deberían recibir aviso, primero: es donde está el problema
        // cuando lo hay.
        uasort($rows, static fn (array $a, array $b): int => [$b['in_scope'], $b['gap']] <=> [$a['in_scope'], $a['gap']]);

        return ['rows' => array_values($rows), 'totals' => $totals, 'missing' => $missing];
    }

    /**
     * Quiénes tienen por dónde recibir el aviso: correo que quieren leer, o
     * cuenta de acceso (que siempre recibe la copia en su bandeja, pase lo que
     * pase con los otros dos canales).
     *
     * Se resuelve para toda la lista de golpe —una consulta de cuentas y otra
     * de preferencias— porque preguntarlo socix a socix sería un par de
     * consultas por cabeza en la pantalla que más gente mira a la vez.
     *
     * @param \App\Entity\WeeklyBasket[] $baskets
     * @return array<int, true> ids de socix, como conjunto
     */
    private function reachablePartnerIds(array $baskets): array
    {
        $partners = [];
        foreach ($baskets as $wb) {
            $partner = $wb->getPartner();
            if ($partner !== null && $partner->getId() !== null) {
                $partners[(int) $partner->getId()] = $partner;
            }
        }

        $reachable = [];

        foreach ($this->users->findByPartners(array_values($partners)) as $user) {
            $partnerId = $user->getPartner()?->getId();
            if ($partnerId !== null) {
                $reachable[(int) $partnerId] = true;
            }
        }

        $withEmail = array_values(array_filter($partners, static fn (Partner $p): bool => (bool) $p->getEmail()));
        foreach ($this->preferences->filter($withEmail, NotificationTopic::PICKUP, NotificationTopic::CHANNEL_EMAIL) as $partner) {
            $reachable[(int) $partner->getId()] = true;
        }

        return $reachable;
    }
}
