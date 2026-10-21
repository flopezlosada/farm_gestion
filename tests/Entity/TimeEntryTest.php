<?php

namespace App\Tests\Entity;

use App\Entity\TimeEntry;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class TimeEntryTest extends TestCase
{
    use StampsTimeEntryRecordedAt;

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

    public function testFichajeEnTiempoRealNoEsDiferido(): void
    {
        $entry = new TimeEntry();
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00'));
        $this->setRecordedAt($entry, new \DateTimeImmutable('2026-06-20 09:00:01'));

        $this->assertFalse($entry->isDeferred());
    }

    public function testFichajeRegistradoUnDiaDespuesEsDiferido(): void
    {
        $entry = new TimeEntry();
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-19 18:00:00'));
        $this->setRecordedAt($entry, new \DateTimeImmutable('2026-06-20 08:30:00'));

        $this->assertTrue($entry->isDeferred());
    }

    public function testRellenoDelMismoDiaHorasDespuesEsDiferido(): void
    {
        // «Rellenar con mi horario» a las 10:30 deja una entrada de las 09:00:
        // mismo día, pero no se fichó en el momento.
        $entry = new TimeEntry();
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00'));
        $this->setRecordedAt($entry, new \DateTimeImmutable('2026-06-20 10:30:00'));

        $this->assertTrue($entry->isDeferred());
    }

    public function testDesfaseDentroDelMargenNoEsDiferido(): void
    {
        $entry = new TimeEntry();
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00'));
        $this->setRecordedAt($entry, (new \DateTimeImmutable('2026-06-20 09:00:00'))->modify(sprintf('+%d seconds', TimeEntry::DEFERRED_MARGIN_SECONDS)));

        $this->assertFalse($entry->isDeferred());
    }

    public function testLoQueElSupervisorApuntaEnElMomentoNoEsDiferido(): void
    {
        // Diferido mide el cuándo, no el quién: el origen ya dice que fue él.
        $entry = (new TimeEntry())->setSource(TimeEntry::SOURCE_SUPERVISOR);
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00'));
        $this->setRecordedAt($entry, new \DateTimeImmutable('2026-06-20 09:00:00'));

        $this->assertFalse($entry->isDeferred());
    }

    public function testLoQueElSupervisorApuntaDespuesEsDiferido(): void
    {
        $entry = (new TimeEntry())->setSource(TimeEntry::SOURCE_SUPERVISOR);
        $entry->setOccurredAt(new \DateTimeImmutable('2026-06-19 18:00:00'));
        $this->setRecordedAt($entry, new \DateTimeImmutable('2026-06-20 08:30:00'));

        $this->assertTrue($entry->isDeferred());
    }

    public function testSinSellosNoEsDiferido(): void
    {
        // Fila aún sin persistir (recordedAt null): no hay con qué comparar.
        $this->assertFalse((new TimeEntry())->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00'))->isDeferred());
    }
}
