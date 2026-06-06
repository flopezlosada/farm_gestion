<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Repository\DeliveryExceptionRepository;
use App\Service\Delivery\BiweeklyCohortResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del BiweeklyCohortResolver. Mockea el repositorio de excepciones
 * para controlar cuántos cierres globales hay entre el ancla y el ciclo, y
 * verifica que la alternancia A/B se desplaza una semana por cada cierre
 * (deuda festivo_desplaza_alternancia).
 */
class BiweeklyCohortResolverTest extends TestCase
{
    private DeliveryExceptionRepository&MockObject $exceptionRepository;
    private BiweeklyCohortResolver $resolver;

    protected function setUp(): void
    {
        $this->exceptionRepository = $this->createMock(DeliveryExceptionRepository::class);
        $this->resolver = new BiweeklyCohortResolver($this->exceptionRepository);
    }

    private function basketOn(string $date): Basket
    {
        return (new Basket())->setDate(new \DateTime($date));
    }

    /**
     * Sin cierres intermedios reproduce el patrón histórico "0,1,0,1" de mayo:
     * 8-may A, 15-may B, 22-may A, 29-may B desde el ancla 8-may = A.
     *
     * @dataProvider patronSinCierres
     */
    public function testSinCierresAlternaCadaSemana(string $date, string $expected): void
    {
        $this->exceptionRepository->method('countGlobalCancellationsBetween')->willReturn(0);

        $this->assertSame($expected, $this->resolver->cohortForBasket($this->basketOn($date)));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function patronSinCierres(): array
    {
        return [
            'ancla 8-may'   => ['2026-05-08', 'A'],
            '15-may'        => ['2026-05-15', 'B'],
            '22-may'        => ['2026-05-22', 'A'],
            '29-may'        => ['2026-05-29', 'B'],
        ];
    }

    /**
     * Un cierre global cancelado entre el ancla y el ciclo invierte la cohorte
     * de ese ciclo en adelante: el grupo que tocaba se corre una semana.
     */
    public function testUnCierreDesplazaLaAlternancia(): void
    {
        $this->exceptionRepository->method('countGlobalCancellationsBetween')->willReturn(1);

        // 22-may sin cierres sería A; con un cierre intermedio pasa a B.
        $this->assertSame('B', $this->resolver->cohortForBasket($this->basketOn('2026-05-22')));
        // 29-may sin cierres sería B; con un cierre pasa a A.
        $this->assertSame('A', $this->resolver->cohortForBasket($this->basketOn('2026-05-29')));
    }

    /**
     * Dos cierres entre medias restauran la paridad original (par - 2 sigue
     * siendo par): la cohorte vuelve a coincidir con el cálculo crudo.
     */
    public function testDosCierresVuelvenAlaParidadOriginal(): void
    {
        $this->exceptionRepository->method('countGlobalCancellationsBetween')->willReturn(2);

        $this->assertSame('A', $this->resolver->cohortForBasket($this->basketOn('2026-05-22')));
        $this->assertSame('B', $this->resolver->cohortForBasket($this->basketOn('2026-05-29')));
    }

    /**
     * El rango consultado al repositorio excluye ambos extremos (ancla y ciclo
     * objetivo): un cierre EXACTAMENTE en el ciclo objetivo no se autodescuenta.
     */
    public function testConsultaElRangoExcluyendoExtremos(): void
    {
        $this->exceptionRepository->expects($this->once())
            ->method('countGlobalCancellationsBetween')
            ->with(
                $this->callback(fn (\DateTimeInterface $after): bool => $after->format('Y-m-d') === '2026-05-08'),
                $this->callback(fn (\DateTimeInterface $before): bool => $before->format('Y-m-d') === '2026-05-29'),
            )
            ->willReturn(0);

        $this->resolver->cohortForBasket($this->basketOn('2026-05-29'));
    }
}
