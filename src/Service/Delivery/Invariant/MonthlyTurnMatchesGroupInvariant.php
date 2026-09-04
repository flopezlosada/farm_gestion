<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\PartnerBasketShare;

/**
 * L31 — Una cesta MENSUAL en un grupo de recogida que de hecho sólo abre en uno
 * de los dos turnos de viernes tiene que llevar ese turno en la ficha.
 *
 * El caso real (Alcobendas, reportado por administración el 4-sep-2026): el
 * grupo cuelga de un punto SEMANAL (Torremocha), pero no tiene ni un socio de
 * cesta semanal y sus seis quincenales recogen todos en el turno B. El grupo,
 * por tanto, sólo abre los viernes de ese turno. Una mensual sin turno cuenta
 * su `day_month_order` sobre los viernes del mes ({@see
 * \App\Service\Delivery\MonthlyOperativeOrderResolver::ordersServedBy}), así
 * que la "2ª entrega" le sale el 2º viernes — que unos meses coincide con una
 * apertura del grupo y otros no. Sonia (107) tenía cesta el 11-sep, un viernes
 * en que su grupo entero no recogía; iba sola a un punto cerrado. Con el turno
 * puesto, las posiciones se cuentan sobre las aperturas del grupo y coincide
 * siempre.
 *
 * El fallo es SILENCIOSO y CÍCLICO: nada avisa, y administración lo descubre
 * mes a mes y lo parchea moviendo la entrega a mano (shifts 45 y 86 de Miguel,
 * julio y agosto de 2026; 108 de Sonia, septiembre). Un socio así no desaparece
 * del listado — aparece, y en el día equivocado, que es peor: la cesta viaja a
 * un punto donde nadie la espera.
 *
 * Y es un fallo LATENTE incluso cuando hoy cuadra: Miriam (96), "1ª entrega"
 * sin turno, acierta mientras el primer viernes del mes caiga en el turno de su
 * grupo, y falla el primer mes en que no (noviembre de 2026, entre otros). Por
 * eso la ley mira la configuración y no el mes en curso.
 *
 * AVISO Y NO ERROR — límite conocido: el modelo no representa el punto FÍSICO
 * de recogida. Un punto puede estar partido en dos grupos ("Tomillares" y
 * "Tomillares individuales", que son la misma dirección — ver la deuda de
 * subgrupos juntos/individuales), y entonces basta un socio semanal en el grupo
 * hermano para que el punto abra todas las semanas y sus mensuales estén bien
 * sin turno. La ley no puede saberlo, así que ahí avisa de más. Se reporta como
 * aviso por eso, y por eso mismo la regla NO vive en la validación de
 * {@see PartnerBasketShare::validateAgainstNodeOffer}: allí impediría guardar
 * una ficha correcta.
 *
 * Fuera de alcance a propósito: los grupos sin ningún quincenal (no hay turno
 * que imponer) y los que cuelgan de un punto quincenal o mensual (ahí el ritmo
 * lo marca el propio punto y el turno se ignora). Las cestas de solo-huevos no
 * cuentan como "abre el punto": su frecuencia es la del periodo de huevos, no
 * la de un turno, y hoy ningún grupo depende de ellas para abrir.
 */
final class MonthlyTurnMatchesGroupInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L31';
    }

    public function name(): string
    {
        return 'Mensual con el turno de su grupo cuando el grupo sólo abre en uno';
    }

    public function severity(): string
    {
        return self::SEVERITY_WARNING;
    }

    public function check(\DateTimeImmutable $from): array
    {
        $violations = [];

        foreach ($this->vigentSharesByGroup($from) as $shares) {
            $turn = $this->singleTurnOf($shares);
            if ($turn === null) {
                continue;
            }

            foreach ($shares as $share) {
                if ($share->getBasketShare()?->isMonthly() !== true) {
                    continue;
                }
                if ($share->getDeliveryGroup() !== null) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s (%d): cesta mensual sin turno en el grupo «%s», donde los quincenales recogen todos en el turno %s. '
                    . 'Su entrega (orden %s) se cuenta sobre los viernes del mes, no sobre las aperturas del grupo: '
                    . 'los meses en que no coinciden, la cesta viaja un día en que el grupo no recoge. [cesta %d]',
                    $share->getPartner()?->getLegalName() ?? '¿socio?',
                    $share->getPartner()?->getId() ?? 0,
                    $share->getPartner()?->getWeeklyBasketGroup()?->getName() ?? '¿grupo?',
                    $turn,
                    $share->getDayMonthOrder() ?? '¿sin orden?',
                    $share->getId(),
                );
            }
        }

        return $violations;
    }

    /**
     * Suscripciones vigentes agrupadas por grupo de recogida, sólo las de
     * grupos que cuelgan de un punto SEMANAL. Se hidratan socio, grupo y punto
     * en la misma consulta: la ley los recorre por cada cesta y sin el join
     * saldría una consulta por socio.
     *
     * @param \DateTimeImmutable $from Inicio de la ventana de validación.
     * @return array<int,list<PartnerBasketShare>> groupId → sus suscripciones vigentes.
     */
    private function vigentSharesByGroup(\DateTimeImmutable $from): array
    {
        $shares = $this->em->createQuery(
            'SELECT pbs, p, wbg, n
             FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.partner p
             JOIN p.weekly_basket_group wbg
             JOIN wbg.node n
             WHERE pbs.is_active = 1
               AND (pbs.end_date IS NULL OR pbs.end_date >= :from)
               AND n.cadence = :weekly
             ORDER BY p.id ASC'
        )
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('weekly', Node::CADENCE_WEEKLY)
            ->getResult();

        $byGroup = [];
        foreach ($shares as $share) {
            $groupId = $share->getPartner()?->getWeeklyBasketGroup()?->getId();
            if ($groupId !== null) {
                $byGroup[$groupId][] = $share;
            }
        }

        return $byGroup;
    }

    /**
     * El único turno de viernes en que este grupo abre, o null si abre todas las
     * semanas (o no se puede afirmar que sólo abra en uno).
     *
     * Un socio con cesta SEMANAL abre el punto cada viernes y zanja la
     * pregunta. Si no hay ninguno, el ritmo lo marcan los quincenales: sólo
     * cuando TODOS coinciden en un turno se puede decir que el grupo abre nada
     * más que esos viernes. Con quincenales en los dos turnos, o sin ningún
     * quincenal, no hay turno que imponer a los mensuales.
     *
     * @param list<PartnerBasketShare> $shares Suscripciones vigentes del grupo.
     * @return string|null Turno A/B, o null.
     */
    private function singleTurnOf(array $shares): ?string
    {
        $turns = [];

        foreach ($shares as $share) {
            $shareId = $share->getBasketShare()?->getId();
            if (in_array($shareId, BasketShare::IDS_WEEKLY, true)) {
                return null;
            }
            if (in_array($shareId, BasketShare::IDS_BIWEEKLY, true) && $share->getDeliveryGroup() !== null) {
                $turns[$share->getDeliveryGroup()] = true;
            }
        }

        return count($turns) === 1 ? array_key_first($turns) : null;
    }
}
