<?php

namespace App\Tests\Entity;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use PHPUnit\Framework\TestCase;

/**
 * Los dos sabores de un intent SIN destino, que es donde estaba el bug: una cesta APARCADA
 * ("no recoge") está pendiente y recolocable, y ocupa sitio en la papelera del calendario;
 * una cesta TRASLADADA SUMANDO también deja su semana sin entrega, pero ya está colocada en
 * otro día y NO está pendiente. Confundirlas la contaba dos veces.
 */
class PartnerDeliveryShiftAccumulatedTest extends TestCase
{
    public function testUnIntentSinDestinoEstaAparcadoMientrasNoSeMarqueElTraslado(): void
    {
        $shift = new PartnerDeliveryShift(new Partner(), $this->basket(1), null);

        $this->assertTrue($shift->isSkip(), 'sin destino: su semana no reparte');
        $this->assertTrue($shift->isParked(), 'y su cesta está pendiente: va a la papelera');
        $this->assertFalse($shift->isAccumulated());
    }

    public function testMarcarElTrasladoLoSacaDeLaPapeleraSinDejarDeVaciarLaSemana(): void
    {
        $to = $this->basket(2);
        $shift = new PartnerDeliveryShift(new Partner(), $this->basket(1), null);

        $shift->setAccumulatedTo($to);

        // isSkip SIGUE siendo cierto a propósito: el generador se apoya en él para no
        // materializar la cesta de patrón de la semana de origen.
        $this->assertTrue($shift->isSkip(), 'la semana de origen sigue sin repartir');
        $this->assertTrue($shift->isAccumulated());
        $this->assertFalse($shift->isParked(), 'la cesta ya está colocada: no va a la papelera');
        $this->assertSame($to, $shift->getAccumulatedTo());
    }

    public function testDeshacerElTrasladoDevuelveElIntentAAparcado(): void
    {
        $shift = new PartnerDeliveryShift(new Partner(), $this->basket(1), null);
        $shift->setAccumulatedTo($this->basket(2));

        $shift->setAccumulatedTo(null);

        $this->assertTrue($shift->isParked());
        $this->assertFalse($shift->isAccumulated());
    }

    public function testUnCambioDeDiaNoPuedeEstarAdemasTrasladadoSumando(): void
    {
        // Estado ilegal irrepresentable: o la cesta se mueve a un destino, o se suma a la
        // entrega de otra semana como extra. Las dos cosas a la vez la duplicarían.
        $shift = new PartnerDeliveryShift(new Partner(), $this->basket(1), $this->basket(2));

        $this->expectException(\LogicException::class);

        $shift->setAccumulatedTo($this->basket(3));
    }

    public function testUnIntentTrasladadoSumandoNoAceptaSemanaDestino(): void
    {
        // La guarda simétrica de la anterior: por el otro orden (repoint sobre un intent ya
        // marcado) el estado ilegal tampoco se puede construir.
        $shift = new PartnerDeliveryShift(new Partner(), $this->basket(1), null);
        $shift->setAccumulatedTo($this->basket(2));

        $this->expectException(\LogicException::class);

        $shift->setToBasket($this->basket(3));
    }

    public function testSoltarElDestinoSiSiguePermitidoParaAparcarUnaCestaMovida(): void
    {
        // skipMovedDelivery re-apunta un cambio O→X a O→null y LUEGO marca el traslado.
        // Ese orden tiene que seguir funcionando (es el camino de acumular sobre un día que
        // ya había recibido una cesta movida).
        $to = $this->basket(3);
        $shift = new PartnerDeliveryShift(new Partner(), $this->basket(1), $this->basket(2));

        $shift->setToBasket(null);
        $shift->setAccumulatedTo($to);

        $this->assertTrue($shift->isAccumulated());
        $this->assertFalse($shift->isParked());
    }

    private function basket(int $id): Basket
    {
        $basket = new Basket();
        $ref = new \ReflectionProperty(Basket::class, 'id');
        $ref->setValue($basket, $id);

        return $basket;
    }
}
