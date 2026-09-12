<?php

namespace App\Tests\Entity;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\SharedBasketChangeRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit de la petición de cambio entre los dos hogares de una cesta compartida.
 *
 * Lo que se vigila aquí es lo que no puede fallar aunque falle todo lo demás: que una
 * respuesta ya dada no se pueda cambiar, y que una petición sobre un reparto que ya
 * pasó no se pueda ejecutar.
 */
class SharedBasketChangeRequestTest extends TestCase
{
    private function basketOn(string $date): Basket
    {
        $basket = $this->createMock(Basket::class);
        $basket->method('getDate')->willReturn(new \DateTimeImmutable($date));

        return $basket;
    }

    private function request(string $kind = SharedBasketChangeRequest::KIND_MOVE): SharedBasketChangeRequest
    {
        return new SharedBasketChangeRequest(new Partner(), new Partner(), $kind);
    }

    public function testNaceEsperandoRespuesta(): void
    {
        $request = $this->request();

        $this->assertTrue($request->isPending());
        $this->assertSame(SharedBasketChangeRequest::STATUS_PENDING, $request->getStatus());
        $this->assertNull($request->getDecidedAt());
    }

    public function testAceptarLaCierraConFecha(): void
    {
        $request = $this->request()->accept();

        $this->assertFalse($request->isPending());
        $this->assertSame(SharedBasketChangeRequest::STATUS_ACCEPTED, $request->getStatus());
        $this->assertNotNull($request->getDecidedAt());
    }

    /**
     * El caso que más daño haría: una negativa que un segundo clic convierte en un sí.
     * Con el cambio ya aplicado detrás, sería una cesta movida contra la voluntad de
     * quien dijo que no.
     */
    public function testUnaRespuestaDadaNoSeCambia(): void
    {
        $request = $this->request()->reject();

        $this->expectException(\LogicException::class);
        $request->accept();
    }

    public function testUnaCanceladaTampocoSeReabre(): void
    {
        $request = $this->request()->cancel();

        $this->expectException(\LogicException::class);
        $request->reject();
    }

    public function testSeguirVivaExigePendienteYReparteFuturo(): void
    {
        $hoy = new \DateTimeImmutable('2026-09-12');

        $futura = $this->request();
        $futura->setBasket($this->basketOn('2026-09-25'));
        $this->assertTrue($futura->isActionable($hoy));

        $pasada = $this->request();
        $pasada->setBasket($this->basketOn('2026-09-04'));
        $this->assertFalse($pasada->isActionable($hoy), 'Un reparto que ya pasó no se puede contestar.');

        $contestada = $this->request();
        $contestada->setBasket($this->basketOn('2026-09-25'));
        $contestada->accept();
        $this->assertFalse($contestada->isActionable($hoy));
    }

    /**
     * La de modalidad no cuelga de ninguna semana: no caduca con un reparto, y sin este
     * caso el `isActionable` de una petición sin basket habría que adivinarlo.
     */
    public function testLaDeModalidadNoCaducaConNingunReparto(): void
    {
        $request = $this->request(SharedBasketChangeRequest::KIND_MODALITY);

        $this->assertTrue($request->isActionable(new \DateTimeImmutable('2030-01-01')));
    }

    public function testElResumenDiceQueSePideYCuando(): void
    {
        $move = $this->request();
        $move->setBasket($this->basketOn('2026-09-18'))->setToBasket($this->basketOn('2026-09-25'));

        $this->assertSame('cambiar la cesta del 18/9 al 25/9', $move->summary());
    }

    /** Una nota en blanco es no tener nota: si no, el aviso pinta unas comillas vacías. */
    public function testLaNotaEnBlancoEsNula(): void
    {
        $request = $this->request();

        $this->assertNull($request->setNote('   ')->getNote());
        $this->assertSame('me voy de viaje', $request->setNote('  me voy de viaje  ')->getNote());
    }
}
