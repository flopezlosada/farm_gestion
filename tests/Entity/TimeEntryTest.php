<?php

namespace App\Tests\Entity;

use App\Entity\TimeEntry;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class TimeEntryTest extends TestCase
{
    public function testEntradaSeReconoceComoIn(): void
    {
        $entry = (new TimeEntry())->setType(TimeEntry::TYPE_IN);

        $this->assertTrue($entry->isIn());
    }

    public function testFichajeNuevoEstaVigenteYNoAnulado(): void
    {
        $entry = new TimeEntry();

        $this->assertTrue($entry->isEffective());
        $this->assertFalse($entry->isVoided());
    }

    public function testVoidDejaLaTrazaCompletaYMarcaAnulado(): void
    {
        $entry = new TimeEntry();
        $supervisor = new User();
        $at = new \DateTimeImmutable('2026-06-20 10:00:00');

        $entry->void($supervisor, 'Hora equivocada', $at);

        $this->assertTrue($entry->isVoided());
        $this->assertFalse($entry->isEffective());
        $this->assertSame($supervisor, $entry->getVoidedBy());
        $this->assertSame('Hora equivocada', $entry->getVoidReason());
        $this->assertEquals($at, $entry->getVoidedAt());
    }

    public function testFichajeEnTiempoRealNoEsManual(): void
    {
        $entry = new TimeEntry();
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00'));
        $this->setRecordedAt($entry, new \DateTimeImmutable('2026-06-20 09:00:01'));

        $this->assertFalse($entry->isManual());
    }

    public function testFichajeRegistradoUnDiaDespuesEsManual(): void
    {
        $entry = new TimeEntry();
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-19 18:00:00'));
        $this->setRecordedAt($entry, new \DateTimeImmutable('2026-06-20 08:30:00'));

        $this->assertTrue($entry->isManual());
    }

    public function testRellenoDelMismoDiaHorasDespuesEsManual(): void
    {
        // «Rellenar con mi horario» a las 10:30 deja una entrada de las 09:00:
        // mismo día, pero no se fichó en el momento.
        $entry = new TimeEntry();
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00'));
        $this->setRecordedAt($entry, new \DateTimeImmutable('2026-06-20 10:30:00'));

        $this->assertTrue($entry->isManual());
    }

    public function testDesfaseDentroDelMargenNoEsManual(): void
    {
        $entry = new TimeEntry();
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00'));
        $this->setRecordedAt($entry, (new \DateTimeImmutable('2026-06-20 09:00:00'))->modify(sprintf('+%d seconds', TimeEntry::MANUAL_MARGIN_SECONDS)));

        $this->assertFalse($entry->isManual());
    }

    public function testLoDelSupervisorEsManualAunqueCoincidanLasHoras(): void
    {
        $entry = (new TimeEntry())->setSource(TimeEntry::SOURCE_SUPERVISOR);
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00'));
        $this->setRecordedAt($entry, new \DateTimeImmutable('2026-06-20 09:00:00'));

        $this->assertTrue($entry->isManual());
    }

    public function testSinSellosNoEsManual(): void
    {
        // Fila aún sin persistir (recordedAt null): no hay con qué comparar.
        $this->assertFalse((new TimeEntry())->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00'))->isManual());
    }

    /**
     * El sello recordedAt lo estampa el PrePersist al guardar y no tiene setter;
     * en un test sin BBDD lo inyectamos por reflexión para ejercitar isManual().
     *
     * @param TimeEntry          $entry      Fichaje a modificar.
     * @param \DateTimeImmutable $recordedAt Sello de inserción simulado.
     * @return void
     */
    private function setRecordedAt(TimeEntry $entry, \DateTimeImmutable $recordedAt): void
    {
        $property = new \ReflectionProperty(TimeEntry::class, 'recordedAt');
        $property->setAccessible(true);
        $property->setValue($entry, $recordedAt);
    }
}
