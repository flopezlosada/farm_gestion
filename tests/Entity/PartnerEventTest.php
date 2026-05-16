<?php

namespace App\Tests\Entity;

use App\Entity\Partner;
use App\Entity\PartnerEvent;
use PHPUnit\Framework\TestCase;

class PartnerEventTest extends TestCase
{
    public function testConstructorAceptaTiposConocidos(): void
    {
        $partner = new Partner();

        $event = new PartnerEvent($partner, PartnerEvent::TYPE_JOIN);

        $this->assertSame(PartnerEvent::TYPE_JOIN, $event->getType());
        $this->assertSame($partner, $event->getPartner());
        $this->assertNotNull($event->getOccurredAt());
        $this->assertNotNull($event->getCreated());
    }

    public function testConstructorRechazaTiposDesconocidos(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PartnerEvent(new Partner(), 'TIPO_INVENTADO');
    }

    public function testOccurredAtSePuedeFijarManualmenteParaImportacionRetroactiva(): void
    {
        $pasado = new \DateTimeImmutable('2020-03-15 10:00:00');

        $event = new PartnerEvent(new Partner(), PartnerEvent::TYPE_LEAVE, $pasado);

        $this->assertSame('2020-03-15', $event->getOccurredAt()->format('Y-m-d'));
    }

    public function testPayloadYNotesYActorOpcionales(): void
    {
        $event = new PartnerEvent(new Partner(), PartnerEvent::TYPE_GROUP_CHANGE_PERMANENT);
        $event->setPayload(['from' => 'A', 'to' => 'B']);
        $event->setNotes('Lo pidió el socio por email');
        $event->setActor('gestor:1');

        $this->assertSame(['from' => 'A', 'to' => 'B'], $event->getPayload());
        $this->assertSame('Lo pidió el socio por email', $event->getNotes());
        $this->assertSame('gestor:1', $event->getActor());
    }

    public function testTodosLosTiposEstanCubiertosPorLaConstanteTYPES(): void
    {
        $constantes = (new \ReflectionClass(PartnerEvent::class))->getConstants();
        $tipos = array_filter(
            $constantes,
            fn ($value, $name) => is_string($name) && str_starts_with($name, 'TYPE_'),
            ARRAY_FILTER_USE_BOTH
        );

        foreach ($tipos as $tipo) {
            $this->assertContains($tipo, PartnerEvent::TYPES);
        }
        $this->assertCount(count($tipos), PartnerEvent::TYPES);
    }
}
