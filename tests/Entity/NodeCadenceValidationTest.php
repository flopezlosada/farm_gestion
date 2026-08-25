<?php

namespace App\Tests\Entity;

use App\Entity\Node;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Blinda la coherencia entre la cadencia de un punto de reparto y su fecha
 * ancla ({@see Node::validateCadenceConsistency}).
 *
 * Motivo: el 25-08-2026 se dio de alta "El Berrueco" como quincenal sin fecha
 * ancla y toda pantalla que calculara fechas de reparto pasó a devolver 500,
 * porque la alternancia es incalculable sin ancla
 * ({@see \App\Service\Delivery\NodeDeliveryDate}). El formulario permitía
 * guardar ese estado imposible. Aquí se cierra esa puerta.
 */
class NodeCadenceValidationTest extends TestCase
{
    /** 2026-05-06 es miércoles; 2026-05-08, viernes. */
    private const WEDNESDAY = '2026-05-06';
    private const FRIDAY = '2026-05-08';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /**
     * El caso que tumbó producción: quincenal sin ancla no debe poder guardarse.
     */
    public function testBiweeklyWithoutAnchorIsRejected(): void
    {
        $node = $this->node(Node::CADENCE_BIWEEKLY, 3, null);

        $this->assertViolationOnAnchor($node, 'necesita una fecha ancla');
    }

    /**
     * Un ancla en otro día de la semana desplaza la cuenta de semanas y el nodo
     * repartiría justo las semanas contrarias, sin error visible. Se rechaza.
     */
    public function testBiweeklyWithAnchorOnAnotherWeekdayIsRejected(): void
    {
        $node = $this->node(Node::CADENCE_BIWEEKLY, 3, self::FRIDAY);

        $this->assertViolationOnAnchor($node, 'debe caer en Miércoles');
    }

    /**
     * Configuración correcta: quincenal con ancla en su propio día de reparto.
     */
    public function testBiweeklyWithAnchorOnItsDeliveryWeekdayIsValid(): void
    {
        $node = $this->node(Node::CADENCE_BIWEEKLY, 3, self::WEDNESDAY);

        $this->assertCount(0, $this->validator->validate($node));
    }

    /**
     * Un ancla huérfana en un punto semanal no molesta hoy, pero reaparece con
     * una fase que nadie eligió si mañana se pasa a quincenal.
     */
    public function testWeeklyWithAnchorIsRejected(): void
    {
        $node = $this->node(Node::CADENCE_WEEKLY, 5, self::FRIDAY);

        $this->assertViolationOnAnchor($node, 'sólo se usa en la cadencia quincenal');
    }

    /**
     * El caso normal de Torremocha: semanal, sin ancla.
     */
    public function testWeeklyWithoutAnchorIsValid(): void
    {
        $node = $this->node(Node::CADENCE_WEEKLY, 5, null);

        $this->assertCount(0, $this->validator->validate($node));
    }

    /**
     * @param string $cadence Una de Node::CADENCE_*.
     * @param int $weekday Día ISO 1=Lunes..7=Domingo.
     * @param string|null $anchor Fecha 'Y-m-d' del ancla, o null.
     * @return Node
     */
    private function node(string $cadence, int $weekday, ?string $anchor): Node
    {
        return (new Node())
            ->setName('El Berrueco')
            ->setDeliveryWeekday($weekday)
            ->setCadence($cadence)
            ->setAnchorDate($anchor !== null ? new \DateTimeImmutable($anchor) : null);
    }

    /**
     * @param Node $node
     * @param string $expectedFragment Trozo del mensaje que debe aparecer.
     * @return void
     */
    private function assertViolationOnAnchor(Node $node, string $expectedFragment): void
    {
        $violations = $this->validator->validate($node);

        $this->assertCount(1, $violations, 'Se esperaba exactamente una violación.');
        $this->assertSame('anchorDate', $violations[0]->getPropertyPath());
        $this->assertStringContainsString($expectedFragment, $violations[0]->getMessage());
    }
}
