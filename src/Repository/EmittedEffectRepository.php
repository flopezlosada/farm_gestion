<?php

namespace App\Repository;

use App\Entity\EmittedEffect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Nada de esto sirve para DECIDIR si un efecto ya se emitió: quien lo decide no
 * consulta, INSERTA y deja que el índice único responda
 * ({@see \App\Service\Cron\EffectLedger}). Un `findOneBy` previo sería
 * exactamente el `if` que pierde la carrera.
 *
 * Lo que sí se lee aquí es el pasado, para rendir cuentas de él: a quién se
 * avisó de un reparto concreto.
 *
 * @extends ServiceEntityRepository<EmittedEffect>
 */
class EmittedEffectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmittedEffect::class);
    }

    /**
     * El apunte más antiguo que existe de esas clases de efecto.
     *
     * Sirve para no mentir hacia atrás. Antes de que este registro existiera se
     * mandaron avisos igualmente, pero no quedó constancia; sin esta frontera,
     * cualquier comprobación sobre un reparto viejo diría que se quedó sin
     * avisar media asociación. Un dato que falta no es un fallo, y confundirlos
     * es la forma más rápida de que nadie vuelva a mirar la pantalla.
     *
     * @param string[] $kinds Clases de efecto.
     */
    public function earliestOccurredOn(array $kinds): ?\DateTimeImmutable
    {
        if ($kinds === []) {
            return null;
        }

        $value = $this->createQueryBuilder('e')
            ->select('MIN(e.occurredOn)')
            ->where('e.kind IN (:kinds)')
            ->setParameter('kinds', $kinds)
            ->getQuery()
            ->getSingleScalarResult();

        return $value === null ? null : new \DateTimeImmutable((string) $value);
    }

    /**
     * Ids de lxs socixs que ya tienen apuntado alguno de esos avisos para una
     * fecha de negocio.
     *
     * Se pregunta aquí y no a la bitácora de envíos porque esta tabla guarda la
     * FECHA DEL REPARTO (`occurred_on`), mientras que la bitácora guarda cuándo
     * salió el correo. Para "¿a quién se avisó del reparto del día 4?" la buena
     * es ésta; da igual que el aviso se mandara el día 3.
     *
     * La referencia se guarda como texto "partner-42", así que se filtra por
     * prefijo y se extrae el número. No es bonito, pero el ledger es genérico a
     * propósito —vale para cualquier efecto, no sólo para socixs— y meterle una
     * clave ajena por este caso sería estrecharlo.
     *
     * @param string[]           $kinds Clases de efecto ("pickup_reminder"…).
     * @param \DateTimeInterface $date  Fecha de negocio del aviso.
     * @return int[] Ids de socix, sin repetir.
     */
    public function partnerIdsByKindsAndDate(array $kinds, \DateTimeInterface $date): array
    {
        if ($kinds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT e.reference AS reference')
            ->where('e.kind IN (:kinds)')
            ->andWhere('e.occurredOn = :date')
            ->andWhere('e.reference LIKE :prefix')
            ->setParameter('kinds', $kinds)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('prefix', 'partner-%')
            ->getQuery()
            ->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) substr((string) $row['reference'], \strlen('partner-'))] = true;
        }

        return array_keys($ids);
    }
}
