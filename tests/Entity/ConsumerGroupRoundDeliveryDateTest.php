<?php

namespace App\Tests\Entity;

use App\Entity\ConsumerGroupRound;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * La fecha de entrega de un pedido no puede ser anterior al cierre de apuntes:
 * el productor recibe las cantidades definitivas cuando el plazo acaba.
 */
class ConsumerGroupRoundDeliveryDateTest extends TestCase
{
    public function testEntregaAnteriorAlCierreNoEsValida(): void
    {
        $round = $this->round('2026-12-16', '2026-12-10');

        self::assertSame(['La fecha de entrega no puede ser anterior al cierre de apuntes (16/12/2026).'], $this->deliveryViolations($round));
    }

    public function testEntregaElMismoDiaDelCierreEsValida(): void
    {
        self::assertSame([], $this->deliveryViolations($this->round('2026-12-16', '2026-12-16')));
    }

    public function testEntregaPosteriorAlCierreEsValida(): void
    {
        self::assertSame([], $this->deliveryViolations($this->round('2026-12-16', '2026-12-24')));
    }

    public function testSinFechaDeEntregaNoHayNadaQueComparar(): void
    {
        self::assertSame([], $this->deliveryViolations($this->round('2026-12-16', null)));
    }

    private function round(string $close, ?string $delivery): ConsumerGroupRound
    {
        return (new ConsumerGroupRound())
            ->setOrdersCloseAt(new \DateTime($close))
            ->setDeliveryDate($delivery !== null ? new \DateTime($delivery) : null);
    }

    /**
     * @return list<string>
     */
    private function deliveryViolations(ConsumerGroupRound $round): array
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $messages = [];
        foreach ($validator->validate($round) as $violation) {
            if ($violation->getPropertyPath() === 'deliveryDate') {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }
}
