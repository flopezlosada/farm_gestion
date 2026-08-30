<?php

namespace App\Repository;

use App\Entity\NotificationOptOut;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationOptOut>
 */
class NotificationOptOutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationOptOut::class);
    }

    /**
     * Lo que un socix tiene apagado, como pares "tema:canal".
     *
     * Devuelve claves y no entidades porque quien pregunta sólo quiere saber si
     * algo está apagado, y un `isset` sobre un mapa evita recorrer la lista por
     * cada comprobación.
     *
     * @param Partner $partner el socix
     *
     * @return array<string, true> mapa "tema:canal" => true
     */
    public function silencedFor(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.topic AS topic', 'o.channel AS channel')
            ->where('o.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getArrayResult();

        $silenced = [];
        foreach ($rows as $row) {
            $silenced[$row['topic'] . ':' . $row['channel']] = true;
        }

        return $silenced;
    }

    /**
     * Lo mismo para MUCHOS socixs de una vez, indexado por id.
     *
     * Existe para los envíos masivos: preguntar socix a socix dentro del bucle
     * del recordatorio son doscientas consultas en la tarea que más gente toca.
     *
     * @param list<Partner> $partners lxs socixs
     *
     * @return array<int, array<string, true>> id de socix => mapa "tema:canal"
     */
    public function silencedForMany(array $partners): array
    {
        if ([] === $partners) {
            return [];
        }

        $rows = $this->createQueryBuilder('o')
            ->select('IDENTITY(o.partner) AS partner_id', 'o.topic AS topic', 'o.channel AS channel')
            ->where('o.partner IN (:partners)')
            ->setParameter('partners', $partners)
            ->getQuery()
            ->getArrayResult();

        $byPartner = [];
        foreach ($rows as $row) {
            $byPartner[(int) $row['partner_id']][$row['topic'] . ':' . $row['channel']] = true;
        }

        return $byPartner;
    }
}
