<?php

namespace App\Service\Staff;

use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Corrige el registro de jornada SIN romper la inmutabilidad: el modelo es
 * append-only, así que nada se edita ni se borra. Las tres operaciones de
 * corrección se expresan sobre eventos:
 *
 *   - corregir la hora  → se ANULA el fichaje erróneo y se inserta uno nuevo con
 *                         la hora correcta (anular + reinsertar).
 *   - anular            → se marca el fichaje como anulado (p. ej. se fichó sin
 *                         querer); no se reinserta nada.
 *   - añadir olvidado   → se inserta un fichaje nuevo con fecha pasada; el sello
 *                         de inserción ({@see TimeEntry::$recordedAt}) lo delata
 *                         como tardío, lo que es honesto y no se puede ocultar.
 *
 * La anulación deja siempre la traza (quién, cuándo, por qué). No hay flujo de
 * aprobación: la corrección se aplica directa (modelo Factorial), y la traza es
 * lo que da garantías.
 */
class TimeEntryCorrector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Corrige la hora de un fichaje: anula el original y crea uno nuevo del mismo
     * tipo con la hora indicada. Devuelve el fichaje nuevo.
     *
     * @param TimeEntry          $original       Fichaje a corregir.
     * @param \DateTimeImmutable $newOccurredAt  Hora correcta (en UTC).
     * @param User               $author         Quién corrige.
     * @param string             $source         TimeEntry::SOURCE_SELF o SOURCE_SUPERVISOR.
     * @param string             $reason         Motivo (queda en la traza).
     * @return TimeEntry El fichaje nuevo.
     */
    public function correctTime(
        TimeEntry $original,
        \DateTimeImmutable $newOccurredAt,
        User $author,
        string $source,
        string $reason,
    ): TimeEntry {
        $original->void($author, $reason, $this->nowUtc());

        $replacement = (new TimeEntry())
            ->setWorker($original->getWorker())
            ->setType($original->getType())
            ->setOccurredAt($newOccurredAt)
            ->setSource($source)
            ->setAuthor($author)
            ->setNote($reason);

        $this->em->persist($replacement);
        $this->em->flush();

        return $replacement;
    }

    /**
     * Anula un fichaje (sin reinsertar): el caso "fiché sin querer". Deja la traza.
     *
     * @param TimeEntry $original Fichaje a anular.
     * @param User      $author   Quién anula.
     * @param string    $reason   Motivo.
     * @return void
     */
    public function voidEntry(TimeEntry $original, User $author, string $reason): void
    {
        $original->void($author, $reason, $this->nowUtc());
        $this->em->flush();
    }

    /**
     * Añade un fichaje olvidado (entrada o salida) con una hora pasada. El desfase
     * con el sello de inserción lo marcará como tardío.
     *
     * @param Worker             $worker     Trabajador.
     * @param string             $type       TimeEntry::TYPE_IN o TYPE_OUT.
     * @param \DateTimeImmutable $occurredAt Hora del fichaje (en UTC).
     * @param User               $author     Quién lo añade.
     * @param string             $source     TimeEntry::SOURCE_SELF o SOURCE_SUPERVISOR.
     * @param string|null        $note       Justificación opcional.
     * @return TimeEntry El fichaje creado.
     */
    public function addEntry(
        Worker $worker,
        string $type,
        \DateTimeImmutable $occurredAt,
        User $author,
        string $source,
        ?string $note = null,
    ): TimeEntry {
        $entry = (new TimeEntry())
            ->setWorker($worker)
            ->setType($type)
            ->setOccurredAt($occurredAt)
            ->setSource($source)
            ->setAuthor($author)
            ->setNote($note);

        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /**
     * Instante actual en hora de Madrid, para el sello de anulación.
     *
     * @return \DateTimeImmutable
     */
    private function nowUtc(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Madrid'));
    }
}
