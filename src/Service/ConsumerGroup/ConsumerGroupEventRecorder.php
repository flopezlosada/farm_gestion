<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupRound;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Escribe en la bitácora de actividad del grupo de consumo ({@see ConsumerGroupEventLog}).
 *
 * Sólo hace `persist()`, nunca `flush()`: se apoya en el flush que ya hace el
 * controller tras la acción que originó el evento, para que quede en la misma
 * transacción (si la acción se revierte, el evento no se queda huérfano).
 */
class ConsumerGroupEventRecorder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function record(string $kind, ?ConsumerGroupRound $round, ?User $actor, string $summary): void
    {
        $log = new ConsumerGroupEventLog();
        $log->setKind($kind);
        $log->setRound($round);
        $log->setActorUser($actor);
        $log->setActorLabel($this->labelFor($actor));
        $log->setSummary($summary);

        $this->em->persist($log);
    }

    private function labelFor(?User $actor): string
    {
        if ($actor === null) {
            return 'Sistema';
        }

        $partner = $actor->getPartner();
        if ($partner !== null) {
            return $partner.' (socia)';
        }

        $producer = $actor->getProducer();
        if ($producer !== null) {
            return $producer.' (productor)';
        }

        return $actor->getEmail() ?: (string) $actor;
    }
}
