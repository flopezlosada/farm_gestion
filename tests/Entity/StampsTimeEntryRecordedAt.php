<?php

namespace App\Tests\Entity;

use App\Entity\TimeEntry;

/**
 * El sello `recordedAt` de un fichaje lo estampa el PrePersist al guardar y no
 * tiene setter; en un test sin BBDD se inyecta por reflexión para ejercitar
 * {@see TimeEntry::isDeferred()}.
 */
trait StampsTimeEntryRecordedAt
{
    /**
     * Fija el sello de inserción de un fichaje sin persistirlo.
     *
     * @param TimeEntry          $entry      Fichaje a modificar.
     * @param \DateTimeImmutable $recordedAt Sello de inserción simulado.
     * @return TimeEntry El mismo fichaje, para encadenar.
     */
    private function setRecordedAt(TimeEntry $entry, \DateTimeImmutable $recordedAt): TimeEntry
    {
        $property = new \ReflectionProperty(TimeEntry::class, 'recordedAt');
        $property->setAccessible(true);
        $property->setValue($entry, $recordedAt);

        return $entry;
    }
}
