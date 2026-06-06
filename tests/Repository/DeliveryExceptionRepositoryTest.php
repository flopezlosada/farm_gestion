<?php

namespace App\Tests\Repository;

use App\Entity\DeliveryException;
use App\Entity\Node;
use App\Repository\DeliveryExceptionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la regla de precedencia entre excepción global y de nodo
 * (DeliveryExceptionRepository::resolvePrecedence). La query en sí no se
 * prueba aquí; solo el desempate, que es lógica pura.
 *
 * Regla: una cancelación global es absoluta (no reparte nadie); un traslado
 * global sí lo puede pisar un override de nodo.
 */
class DeliveryExceptionRepositoryTest extends TestCase
{
    public function testCancelacionGlobalGanaSobreTrasladoDeNodo(): void
    {
        $global = $this->cancellation();          // cierre total
        $nodeSpecific = $this->shift('2026-12-23'); // Midori trasladado

        // El cierre total manda: no reparte nadie, ni Midori.
        $this->assertSame($global, DeliveryExceptionRepository::resolvePrecedence($global, $nodeSpecific));
    }

    public function testTrasladoGlobalLoPisaElOverrideDeNodo(): void
    {
        $global = $this->shift('2026-12-23');       // todos al miércoles
        $nodeSpecific = $this->shift('2026-12-24'); // este nodo prefiere el jueves

        $this->assertSame($nodeSpecific, DeliveryExceptionRepository::resolvePrecedence($global, $nodeSpecific));
    }

    public function testSinGlobalManaElDeNodo(): void
    {
        $nodeSpecific = $this->shift('2026-12-23');

        $this->assertSame($nodeSpecific, DeliveryExceptionRepository::resolvePrecedence(null, $nodeSpecific));
    }

    public function testSinNodoManaElGlobal(): void
    {
        $global = $this->shift('2026-12-23');

        $this->assertSame($global, DeliveryExceptionRepository::resolvePrecedence($global, null));
    }

    public function testCancelacionGlobalSinNodo(): void
    {
        $global = $this->cancellation();

        $this->assertSame($global, DeliveryExceptionRepository::resolvePrecedence($global, null));
    }

    public function testNingunaDevuelveNull(): void
    {
        $this->assertNull(DeliveryExceptionRepository::resolvePrecedence(null, null));
    }

    private function cancellation(): DeliveryException
    {
        return (new DeliveryException())->setShiftedDate(null);
    }

    private function shift(string $date, ?Node $node = null): DeliveryException
    {
        return (new DeliveryException())
            ->setShiftedDate(new \DateTimeImmutable($date))
            ->setNode($node);
    }
}
