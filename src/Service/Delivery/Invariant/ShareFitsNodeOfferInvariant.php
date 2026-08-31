<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerBasketShare;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * L30 — Cesta coherente con su punto de recogida: ninguna suscripción VIGENTE
 * pide algo que su punto no sirve (modalidad que no cabe en su cadencia, cesta
 * mensual sin entrega del mes o con una que el punto no abre todos los meses,
 * quincenal sin turno de viernes en un punto semanal).
 *
 * Por qué es una ley y no basta con la validación del formulario: la validación
 * sólo actúa cuando alguien edita la cesta desde la aplicación. El estado puede
 * entrar por otras puertas — un UPDATE a mano en producción, una importación,
 * un dato que ya estaba mal antes de que existiera la regla. Y el fallo es de
 * los más caros del dominio porque es SILENCIOSO: la query de mensuales filtra
 * por `day_month_order IN (...)` y NULL no casa con nada, así que el socio
 * simplemente no aparece en ningún listado. Fue el caso de los dos socios de
 * El Berrueco, invisibles casi un mes sin que saltara nada (2026-08-26).
 *
 * La regla NO se reimplementa aquí: se ejecuta la misma que impone el
 * formulario, {@see PartnerBasketShare::validateAgainstNodeOffer}, invocada por
 * su nombre para no arrastrar el resto de constraints de la entidad (el importe
 * de las compartidas, por ejemplo, no es asunto del reparto). Así ley y
 * validación no pueden divergir: se cambia la regla en un sitio y las dos la
 * siguen.
 *
 * Alcance deliberado: sólo las suscripciones ACTIVAS y aún vigentes. Un tramo
 * ya cerrado del histórico puede ser legítimamente incoherente con el punto de
 * HOY — el punto pudo cambiar de cadencia después — y exigirle la regla actual
 * sería un falso positivo sobre historia que ya no se reparte.
 */
final class ShareFitsNodeOfferInvariant extends AbstractInvariant
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct($em);
    }

    public function code(): string
    {
        return 'L30';
    }

    public function name(): string
    {
        return 'La cesta cabe en el punto de recogida que la sirve';
    }

    public function check(\DateTimeImmutable $from): array
    {
        // Se hidratan de una vez socio, grupo y nodo: la regla los recorre para
        // cada cesta y sin el join saldría una consulta por socio.
        $shares = $this->em->createQuery(
            'SELECT pbs, p, wbg, n
             FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.partner p
             LEFT JOIN p.weekly_basket_group wbg
             LEFT JOIN wbg.node n
             WHERE pbs.is_active = 1
               AND (pbs.end_date IS NULL OR pbs.end_date >= :from)
             ORDER BY p.id ASC'
        )
            ->setParameter('from', $from->format('Y-m-d'))
            ->getResult();

        $rule = new Callback(callback: 'validateAgainstNodeOffer');

        $violations = [];
        foreach ($shares as $share) {
            foreach ($this->validator->validate($share, $rule) as $violation) {
                $violations[] = sprintf(
                    '%s (%d): %s [cesta %d, %s en %s]',
                    // Nombre LEGAL, no el de reparto: quien lea el informe va a
                    // buscar la ficha, y el de reparto puede ser un apodo o el
                    // nombre de a quien se le regala la cesta.
                    $share->getPartner()?->getLegalName() ?? '¿socio?',
                    $share->getPartner()?->getId() ?? 0,
                    $violation->getMessage(),
                    $share->getId(),
                    $share->getBasketShare()?->getName() ?? '¿modalidad?',
                    $share->getPartner()?->getWeeklyBasketGroup()?->getNode()?->getName() ?? 'sin punto asignado',
                );
            }
        }

        return $violations;
    }
}
