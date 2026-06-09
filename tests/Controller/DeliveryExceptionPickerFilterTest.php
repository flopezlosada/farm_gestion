<?php

namespace App\Tests\Controller;

use App\Controller\DeliveryExceptionController;
use App\Entity\Basket;
use App\Entity\DeliveryException;
use App\Entity\Node;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la lógica pura del picker de excepciones: construcción de
 * claves de alcance y exclusión de la propia excepción al editar. La query
 * (findFromDate) y el render no se prueban aquí; solo la decisión, que es
 * lógica pura — mismo enfoque que DeliveryExceptionRepositoryTest.
 *
 * Regla: una fecha ya con excepción no se vuelve a ofrecer en el picker;
 * en edición, la propia excepción sí sigue disponible.
 */
class DeliveryExceptionPickerFilterTest extends TestCase
{
    public function testScopeKeyDistingueGlobalDeNodo(): void
    {
        // Alcance global (sin nodo) → sufijo vacío; con nodo → su id.
        $this->assertSame('5|', DeliveryExceptionController::scopeKey(5, null));
        $this->assertSame('5|3', DeliveryExceptionController::scopeKey(5, 3));
    }

    public function testOccupiedKeysFromConstruyeClavesGlobalYNodo(): void
    {
        $existing = [
            $this->exception(1, 100, null), // cierre global del ciclo 100
            $this->exception(2, 100, 7),    // traslado del nodo 7 ese mismo ciclo
        ];

        $keys = DeliveryExceptionController::occupiedKeysFrom($existing, null);

        $this->assertArrayHasKey('100|', $keys);
        $this->assertArrayHasKey('100|7', $keys);
        $this->assertCount(2, $keys);
    }

    public function testOccupiedKeysFromExcluyeLaPropiaAlEditar(): void
    {
        $current = $this->exception(2, 100, 7);
        $existing = [
            $this->exception(1, 100, null),
            $current, // la que se está editando
        ];

        $keys = DeliveryExceptionController::occupiedKeysFrom($existing, $current);

        // La propia sigue disponible (su tile debe poder pintarse); el resto, ocupado.
        $this->assertArrayHasKey('100|', $keys);
        $this->assertArrayNotHasKey('100|7', $keys);
    }

    /**
     * Crea una DeliveryException mockeada con id, ciclo y nodo concretos.
     *
     * @param int $id Id de la propia excepción.
     * @param int $basketId Id del ciclo (Basket).
     * @param int|null $nodeId Id del nodo, o null para una excepción global.
     * @return DeliveryException
     */
    private function exception(int $id, int $basketId, ?int $nodeId): DeliveryException
    {
        $basket = $this->createMock(Basket::class);
        $basket->method('getId')->willReturn($basketId);

        $node = null;
        if ($nodeId !== null) {
            $node = $this->createMock(Node::class);
            $node->method('getId')->willReturn($nodeId);
        }

        $exception = $this->createMock(DeliveryException::class);
        $exception->method('getId')->willReturn($id);
        $exception->method('getBasket')->willReturn($basket);
        $exception->method('getNode')->willReturn($node);

        return $exception;
    }
}
