<?php

namespace App\Tests\Service\Email;

use App\Entity\Partner;
use App\Entity\PartnerEvent;
use App\Service\Email\DeliveryChangeFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Formateo de los cambios autoservicio para el email de resumen a admin.
 * Lógica pura: no necesita contenedor ni BBDD.
 */
class DeliveryChangeFormatterTest extends TestCase
{
    private DeliveryChangeFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new DeliveryChangeFormatter();
    }

    public function testHumanTypeEtiquetaCadaTipoConocido(): void
    {
        $this->assertSame('No recoge cesta', $this->formatter->humanType(PartnerEvent::TYPE_BASKET_SKIP, []));
        $this->assertSame('Vuelve a recoger', $this->formatter->humanType(PartnerEvent::TYPE_BASKET_UNSKIP, []));
        $this->assertSame('Cambia de nodo', $this->formatter->humanType(PartnerEvent::TYPE_NODE_CHANGE, []));
        $this->assertSame('Cambia de viernes', $this->formatter->humanType(PartnerEvent::TYPE_WEEK_SWAP, []));
    }

    public function testWeekSwapCanceladoSeDistinguePorElPayload(): void
    {
        $this->assertSame(
            'Cancela cambio de viernes',
            $this->formatter->humanType(PartnerEvent::TYPE_WEEK_SWAP, ['cancelled' => true]),
        );
    }

    public function testHumanTypeDesconocidoDevuelveElTipoCrudo(): void
    {
        $this->assertSame('ALGO_RARO', $this->formatter->humanType('ALGO_RARO', []));
    }

    public function testHumanDescriptionNodeChangeConYSinDatos(): void
    {
        $this->assertSame(
            'Madrid → Sierra',
            $this->formatter->humanDescription(PartnerEvent::TYPE_NODE_CHANGE, ['from_group_name' => 'Madrid', 'to_group_name' => 'Sierra']),
        );
        // Sin claves en el payload cae a guiones, nunca revienta.
        $this->assertSame('— → —', $this->formatter->humanDescription(PartnerEvent::TYPE_NODE_CHANGE, []));
    }

    public function testHumanDescriptionWeekSwap(): void
    {
        $this->assertSame(
            'del 2026-06-05 al 2026-06-12',
            $this->formatter->humanDescription(PartnerEvent::TYPE_WEEK_SWAP, ['from_date' => '2026-06-05', 'to_date' => '2026-06-12']),
        );
    }

    public function testHumanDescriptionOtrosTiposVacia(): void
    {
        $this->assertSame('', $this->formatter->humanDescription(PartnerEvent::TYPE_BASKET_SKIP, []));
    }

    public function testRenderableRowFormateaFilaCompleta(): void
    {
        $partner = (new Partner())->setName('Lucía')->setSurname('García');
        $event = new PartnerEvent($partner, PartnerEvent::TYPE_NODE_CHANGE, new \DateTimeImmutable('2026-06-10 14:30'));
        $event->setPayload(['from_group_name' => 'Madrid', 'to_group_name' => 'Sierra']);

        $row = $this->formatter->renderableRow($event);

        $this->assertSame('10/06/2026 14:30', $row['when']);
        $this->assertSame('García, Lucía', $row['partner']);
        $this->assertSame('Cambia de nodo', $row['type']);
        $this->assertSame('Madrid → Sierra', $row['detail']);
    }

    public function testRenderableRowSinNombreCaeEnPlaceholder(): void
    {
        $event = new PartnerEvent(new Partner(), PartnerEvent::TYPE_BASKET_SKIP, new \DateTimeImmutable('2026-06-10 09:00'));

        $row = $this->formatter->renderableRow($event);

        $this->assertSame('Sin nombre', $row['partner']);
    }
}
