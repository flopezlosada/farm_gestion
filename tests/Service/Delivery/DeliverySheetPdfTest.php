<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Service\Delivery\DeliveryModeResolver;
use App\Service\Delivery\DeliverySheetPdf;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\NodeDeliverySheet;
use App\Service\Delivery\WeeklyBasketGenerator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * Unit del generador del listado en PDF. Cubre lo que antes vivía suelto dentro
 * del controlador y no tenía ninguna prueba: la decisión piedra-vs-dibujo POR
 * NODO y el caso de "ningún nodo reparte", que es el que decide si la tarea
 * programada manda correo o se calla.
 *
 * Twig va mockeado (el HTML del listado se prueba en NodeDeliverySheetTest), pero
 * dompdf corre de verdad: es la única forma de comprobar que lo devuelto son
 * bytes de un PDF y no una cadena cualquiera.
 */
class DeliverySheetPdfTest extends TestCase
{
    private const HTML = '<!DOCTYPE html><html lang="es"><body><p>Listado</p></body></html>';

    public function testSheetForLeeDePiedraCuandoLaSemanaEstaMaterializada(): void
    {
        $node = $this->createMock(Node::class);
        $basket = $this->createMock(Basket::class);
        $stone = [['name' => 'de piedra']];

        $sheetBuilder = $this->createMock(NodeDeliverySheet::class);
        $sheetBuilder->expects($this->once())->method('build')->with($node, $basket)->willReturn($stone);
        $sheetBuilder->expects($this->never())->method('shape');

        $pdf = $this->pdf(DeliveryModeResolver::STONE, $sheetBuilder);

        $this->assertSame($stone, $pdf->sheetFor($node, $basket));
    }

    public function testSheetForDibujaAlVueloCuandoTocaPeroNoHayPiedra(): void
    {
        $node = $this->createMock(Node::class);
        $basket = $this->createMock(Basket::class);
        $drawn = [['name' => 'dibujada']];

        $sheetBuilder = $this->createMock(NodeDeliverySheet::class);
        $sheetBuilder->expects($this->never())->method('build');
        $sheetBuilder->method('helperLines')->willReturn(['helper']);
        // La hoja dibujada es la proyección MÁS las líneas de quien ayuda en el
        // reparto: si se pierden, el listado sale sin las personas que montan las
        // cestas.
        $sheetBuilder->expects($this->once())
            ->method('shape')
            ->with(['proyectada', 'helper'])
            ->willReturn($drawn);

        $generator = $this->createMock(WeeklyBasketGenerator::class);
        $generator->method('projectLinesForNode')->willReturn(['proyectada']);

        $pdf = $this->pdf(DeliveryModeResolver::DRAW, $sheetBuilder, $generator);

        $this->assertSame($drawn, $pdf->sheetFor($node, $basket));
    }

    public function testSheetForDevuelveNullCuandoElNodoNoRepartEseDia(): void
    {
        $pdf = $this->pdf(DeliveryModeResolver::EMPTY);

        $this->assertNull($pdf->sheetFor($this->createMock(Node::class), $this->createMock(Basket::class)));
    }

    /**
     * Un PDF de cero hojas no es un documento. Devolver null (y no una cadena
     * vacía ni un PDF en blanco) es lo que permite a la pantalla avisar y a la
     * tarea programada no mandar un correo con un adjunto vacío.
     */
    public function testRenderWeeklyDevuelveNullSiNingunNodoReparte(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->never())->method('render');

        $pdf = $this->pdf(DeliveryModeResolver::EMPTY, twig: $twig);

        $this->assertNull($pdf->renderWeekly($this->createMock(Basket::class), [$this->createMock(Node::class)]));
    }

    public function testRenderWeeklyPasaUnaHojaPorNodoQueRepartYDevuelveUnPdf(): void
    {
        $node = $this->createMock(Node::class);
        $basket = $this->createMock(Basket::class);
        $day = new \DateTimeImmutable('2026-09-02');

        $sheetBuilder = $this->createMock(NodeDeliverySheet::class);
        $sheetBuilder->method('build')->willReturn(['hoja']);

        $nodeDeliveryDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDeliveryDate->method('physicalDateFor')->willReturn($day);

        $twig = $this->createMock(Environment::class);
        // La fecha FÍSICA del nodo viaja a la plantilla junto a la hoja: es lo que
        // hace que el listado de Madrid diga miércoles y el de la Sierra viernes.
        $twig->expects($this->once())
            ->method('render')
            ->with('delivery/printable.html.twig', [
                'basket' => $basket,
                'sheets' => [['node' => $node, 'physical_date' => $day, 'sheet' => ['hoja']]],
            ])
            ->willReturn(self::HTML);

        $pdf = $this->pdf(DeliveryModeResolver::STONE, $sheetBuilder, twig: $twig, nodeDeliveryDate: $nodeDeliveryDate);

        $this->assertStringStartsWith('%PDF', (string) $pdf->renderWeekly($basket, [$node]));
    }

    public function testElNombreDelFicheroLlevaLaFechaDelCiclo(): void
    {
        $basket = $this->createMock(Basket::class);
        $basket->method('getDate')->willReturn(new \DateTime('2026-09-04'));

        $this->assertSame('reparto-2026-09-04.pdf', $this->pdf(DeliveryModeResolver::EMPTY)->weeklyFilename($basket));
        $this->assertSame('reparto-mensual-2026-09.pdf', $this->pdf(DeliveryModeResolver::EMPTY)->monthlyFilename(2026, 9));
    }

    /**
     * Monta el servicio con el modo de reparto fijado y el resto de colaboradores
     * en mocks vacíos, para que cada prueba sólo declare lo que le importa.
     */
    private function pdf(
        string $mode,
        ?NodeDeliverySheet $sheetBuilder = null,
        ?WeeklyBasketGenerator $generator = null,
        ?Environment $twig = null,
        ?NodeDeliveryDate $nodeDeliveryDate = null,
    ): DeliverySheetPdf {
        $modeResolver = $this->createMock(DeliveryModeResolver::class);
        $modeResolver->method('mode')->willReturn($mode);

        return new DeliverySheetPdf(
            $twig ?? $this->createMock(Environment::class),
            $sheetBuilder ?? $this->createMock(NodeDeliverySheet::class),
            $modeResolver,
            $generator ?? $this->createMock(WeeklyBasketGenerator::class),
            $nodeDeliveryDate ?? $this->createMock(NodeDeliveryDate::class),
        );
    }
}
