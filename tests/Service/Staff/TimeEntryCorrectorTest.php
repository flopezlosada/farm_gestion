<?php

namespace App\Tests\Service\Staff;

use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Repository\TimeEntryRepository;
use App\Service\Staff\PunchSequenceException;
use App\Service\Staff\PunchSequenceGuard;
use App\Service\Staff\TimeEntryCorrector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class TimeEntryCorrectorTest extends TestCase
{
    /**
     * @param TimeEntry[] $dayEntries Fichajes vigentes que el repo devolverá para el día.
     */
    private function corrector(array $dayEntries = []): TimeEntryCorrector
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(TimeEntryRepository::class);
        $repo->method('findEffectiveForWorkerBetween')->willReturn($dayEntries);

        return new TimeEntryCorrector(
            $em,
            new MockClock(new \DateTimeImmutable('2026-06-20 10:00:00', new \DateTimeZone('UTC'))),
            $repo,
            new PunchSequenceGuard(),
        );
    }

    private function entry(string $type, string $datetime): TimeEntry
    {
        return (new TimeEntry())
            ->setType($type)
            ->setOccurredAt(new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')));
    }

    public function testCorregirHoraAnulaElOriginalYCreaUnoNuevo(): void
    {
        $author = new User();
        // El día tiene una entrada a las 09:00, así que mover la salida a las 17:00 es válido.
        $in = $this->entry(TimeEntry::TYPE_IN, '2026-06-20 09:00:00');
        $original = (new TimeEntry())
            ->setWorker(new Worker())
            ->setType(TimeEntry::TYPE_OUT)
            ->setOccurredAt(new \DateTimeImmutable('2026-06-20 16:00:00', new \DateTimeZone('UTC')));

        $new = $this->corrector([$in])->correctTime(
            $original,
            new \DateTimeImmutable('2026-06-20 17:00:00', new \DateTimeZone('UTC')),
            $author,
            TimeEntry::SOURCE_SUPERVISOR,
            'La salida real fue a las 17:00',
        );

        // El original queda anulado, con su traza, y conserva su hora.
        $this->assertTrue($original->isVoided());
        $this->assertSame($author, $original->getVoidedBy());
        $this->assertSame('La salida real fue a las 17:00', $original->getVoidReason());
        $this->assertSame('16:00', $original->getOccurredAt()->format('H:i'));

        // El nuevo es del mismo tipo, con la hora corregida y vigente.
        $this->assertFalse($new->isVoided());
        $this->assertSame(TimeEntry::TYPE_OUT, $new->getType());
        $this->assertSame('17:00', $new->getOccurredAt()->format('H:i'));
        $this->assertSame(TimeEntry::SOURCE_SUPERVISOR, $new->getSource());
        $this->assertSame($author, $new->getAuthor());
    }

    public function testAnularDejaElFichajeAnuladoConMotivo(): void
    {
        $author = new User();
        $entry = (new TimeEntry())->setType(TimeEntry::TYPE_IN)
            ->setOccurredAt(new \DateTimeImmutable('2026-06-20 09:00:00', new \DateTimeZone('UTC')));

        $this->corrector()->voidEntry($entry, $author, 'Fichaje duplicado');

        $this->assertTrue($entry->isVoided());
        $this->assertSame('Fichaje duplicado', $entry->getVoidReason());
    }

    public function testAnadirOlvidadoCreaFichajeVigente(): void
    {
        $author = new User();
        $worker = new Worker();

        $entry = $this->corrector()->addEntry(
            $worker,
            TimeEntry::TYPE_IN,
            new \DateTimeImmutable('2026-06-19 08:30:00', new \DateTimeZone('UTC')),
            $author,
            TimeEntry::SOURCE_SUPERVISOR,
            'Olvidó fichar la entrada',
        );

        $this->assertSame($worker, $entry->getWorker());
        $this->assertSame(TimeEntry::TYPE_IN, $entry->getType());
        $this->assertSame('2026-06-19 08:30', $entry->getOccurredAt()->format('Y-m-d H:i'));
        $this->assertFalse($entry->isVoided());
    }

    public function testAnadirSalidaSinEntradaPreviaSeRechaza(): void
    {
        $this->expectException(PunchSequenceException::class);

        // Día vacío: una salida sin entrada antes rompe el orden.
        $this->corrector()->addEntry(
            new Worker(),
            TimeEntry::TYPE_OUT,
            new \DateTimeImmutable('2026-06-19 14:00:00', new \DateTimeZone('UTC')),
            new User(),
            TimeEntry::SOURCE_SUPERVISOR,
            'salida suelta',
        );
    }
}
