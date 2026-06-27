<?php

namespace App\Service\Staff;

use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Repository\TimeEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reloj de fichaje: el único punto que crea {@see TimeEntry}. Centraliza dos
 * cosas que no deben vivir en el controller:
 *
 *  1. Decidir si el siguiente fichaje es entrada o salida, alternando a partir
 *     del último fichaje VIGENTE del trabajador (sin tramo abierto → entrada).
 *  2. Estampar el instante en hora de Madrid. El reloj se inyecta ({@see ClockInterface})
 *     para poder fijar "ahora" en los tests con un MockClock.
 *
 * No edita ni borra: el modelo es append-only ({@see TimeEntry}). Corregir un
 * fichaje se hace anulando + reinsertando, no aquí.
 */
class TimeClock
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimeEntryRepository $timeEntries,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Tipo que correspondería al siguiente fichaje a partir del último vigente:
     * entrada si no hay ninguno o si el último fue una salida; salida si el
     * último fue una entrada (hay un tramo abierto). Lógica pura, sin estado, así
     * es trivial de testear y la comparte la vista para pintar el botón correcto.
     *
     * @param TimeEntry|null $last Último fichaje vigente del trabajador, o null.
     * @return string TimeEntry::TYPE_IN o TimeEntry::TYPE_OUT.
     */
    public static function nextTypeAfter(?TimeEntry $last): string
    {
        return ($last !== null && $last->isIn()) ? TimeEntry::TYPE_OUT : TimeEntry::TYPE_IN;
    }

    /**
     * ¿Tiene el trabajador un tramo de jornada abierto? (su último fichaje
     * vigente es una entrada sin salida posterior).
     *
     * @param Worker $worker Trabajador.
     * @return bool
     */
    public function hasOpenInterval(Worker $worker): bool
    {
        $last = $this->timeEntries->findLastEffectiveForWorker($worker);

        return $last !== null && $last->isIn();
    }

    /**
     * Ficha el siguiente evento del trabajador en tiempo real: alterna
     * entrada/salida según su estado, estampa el instante actual en hora de Madrid, marca el
     * origen como propio del trabajador y deja constancia del autor. Persiste y
     * confirma.
     *
     * @param Worker $worker Trabajador que ficha.
     * @param User   $author Login que realiza la acción (normalmente el propio trabajador).
     * @return TimeEntry El fichaje creado.
     */
    public function clockNext(Worker $worker, User $author): TimeEntry
    {
        $last = $this->timeEntries->findLastEffectiveForWorker($worker);

        $entry = (new TimeEntry())
            ->setWorker($worker)
            ->setType(self::nextTypeAfter($last))
            ->setOccurredAt($this->now())
            ->setSource(TimeEntry::SOURCE_SELF)
            ->setAuthor($author);

        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /**
     * Instante actual en hora de Madrid. Los fichajes se guardan y se muestran en
     * la hora local de la asociación (huso único): así la hora almacenada coincide
     * con la que ve y declara la persona, sin conversiones intermedias.
     *
     * @return \DateTimeImmutable
     */
    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Madrid'));
    }
}
