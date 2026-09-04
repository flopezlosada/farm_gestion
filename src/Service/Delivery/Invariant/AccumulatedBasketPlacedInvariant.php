<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerDeliveryShift;

/**
 * L31 — Una cesta trasladada SUMANDO está realmente colocada donde dice. Un intent con
 * `accumulatedTo` afirma dos cosas a la vez: que su semana de origen no reparte, y que esa
 * cesta vive como cesta extra ({@see PartnerBasketExtra}) en la semana destino. Si el extra
 * del destino ya no está, la afirmación es falsa y la cesta NO ESTÁ EN NINGÚN SITIO: el
 * origen quedó vacío por el intent y el destino no la lleva. Desaparece del listado impreso
 * sin que nada se queje.
 *
 * Es la ley que faltaba cuando el traslado sumando no se distinguía de un "no recoge": no
 * hay conservación de CESTAS que lo vigile (L17 solo cuenta docenas de huevo) y L29 tampoco
 * lo ve, porque compara extra contra skip en la MISMA semana y aquí el intent está en una y
 * el extra en otra.
 *
 * Cómo se llega al estado roto: cualquier camino que retire el añadido del día destino sin
 * ajustar el intent. Están todos cubiertos desde
 * {@see \App\Service\Delivery\ExtraBasketEditor::removeExtra()}, que según el caso devuelve
 * la cesta a PENDIENTE (el día se vacía) o re-apunta el intent (el añadido viaja con la
 * cesta a otro día). Lo que esta ley encuentre es un camino nuevo que se olvidó de eso, o
 * un arreglo a mano en la base de datos.
 *
 * ERROR, no warning: no hay lectura legítima de este estado. Una cesta que nadie va a
 * recibir es exactamente el fallo que el socix nota y la gestión no.
 */
final class AccumulatedBasketPlacedInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L31';
    }

    public function name(): string
    {
        return 'La cesta trasladada sumando existe en su semana destino';
    }

    public function check(\DateTimeImmutable $from): array
    {
        // Ventana por la semana DESTINO: es donde la cesta debería aparecer, y es la que
        // aún se puede arreglar. Un traslado cuyo destino ya pasó es historia.
        $shifts = $this->em->createQuery(
            'SELECT IDENTITY(s.partner) AS pid, p.name AS pname, p.surname AS psurname,
                    fb.date AS fromdate, ab.id AS abid, ab.date AS abdate
             FROM ' . PartnerDeliveryShift::class . ' s
             JOIN s.partner p
             JOIN s.fromBasket fb
             JOIN s.accumulatedTo ab
             WHERE s.component IS NULL AND ab.date >= :from'
        )->setParameter('from', $from)->getArrayResult();

        if ($shifts === []) {
            return [];
        }

        // Semanas que SÍ tienen añadido, por (socio, semana). Una sola consulta: los
        // traslados son pocos, pero recorrerlos con un findBy por fila sería un N+1.
        $extras = $this->em->createQuery(
            'SELECT IDENTITY(x.partner) AS pid, IDENTITY(x.basket) AS bid
             FROM ' . PartnerBasketExtra::class . ' x'
        )->getArrayResult();

        $hasExtra = [];
        foreach ($extras as $x) {
            $hasExtra[$x['pid'] . '-' . $x['bid']] = true;
        }

        $violations = [];
        foreach ($shifts as $s) {
            if (isset($hasExtra[$s['pid'] . '-' . $s['abid']])) {
                continue;
            }
            $violations[] = sprintf(
                '%s %s (%d): la cesta del %s dice estar sumada a la del %s, pero esa semana no lleva ningún añadido — la cesta no está en ningún sitio.',
                $s['pname'] ?? '¿?',
                $s['psurname'] ?? '',
                $s['pid'],
                $this->d($s['fromdate'] instanceof \DateTimeInterface ? $s['fromdate'] : null),
                $this->d($s['abdate'] instanceof \DateTimeInterface ? $s['abdate'] : null),
            );
        }

        return $violations;
    }
}
