<?php

namespace App\Tests\Entity;

use App\Entity\Node;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Blinda el opt-in del montaje de cestas con voluntariado: cuándo hay
 * convocatoria, a qué hora cae y qué configuraciones no se pueden guardar
 * ({@see Node::deliveryPrepWindowFor}, {@see Node::validateDeliveryPrep}).
 *
 * Importa porque de aquí sale sola la convocatoria semanal de cada punto y el
 * bloque "quién te prepara la cesta" del panel del socix. Una hora mal contada
 * no da un error: da una tarea a la que nadie llega a tiempo, o una tarjeta que
 * no encuentra a quien sí estuvo montando.
 */
class NodeDeliveryPrepTest extends TestCase
{
    /** Un viernes de reparto cualquiera. */
    private const FRIDAY = '2026-09-04';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /**
     * Un punto que no monta con voluntariado no convoca a nadie. Es el estado de
     * todos los puntos menos Torremocha, y el que impide llenar de tareas vacías
     * a quienes montan las cestas de otra manera.
     */
    public function testANodeThatDoesNotPrepWithVolunteersHasNoWindow(): void
    {
        $node = $this->node();

        $this->assertNull($node->deliveryPrepWindowFor(new \DateTimeImmutable(self::FRIDAY)));
    }

    /**
     * Marcado y con hora, el montaje cae el mismo día de la entrega.
     */
    public function testSameDayPrepFallsOnTheDeliveryDay(): void
    {
        $node = $this->prepNode(0, '17:00');

        [$start] = $node->deliveryPrepWindowFor(new \DateTimeImmutable(self::FRIDAY));

        $this->assertSame('2026-09-04 17:00', $start->format('Y-m-d H:i'));
    }

    /**
     * El caso de Torremocha: las cestas se montan la tarde anterior. Es lo que
     * antes obligaba a barrer una ventana de dos días y quedarse con lo que
     * hubiera dentro.
     */
    public function testEvePrepFallsOnTheDayBefore(): void
    {
        $node = $this->prepNode(-1, '18:30');

        [$start] = $node->deliveryPrepWindowFor(new \DateTimeImmutable(self::FRIDAY));

        $this->assertSame('2026-09-03 18:30', $start->format('Y-m-d H:i'));
    }

    /**
     * Un punto grande puede empezar dos días antes.
     */
    public function testPrepCanStartTwoDaysBefore(): void
    {
        $node = $this->prepNode(-2, '10:00');

        [$start] = $node->deliveryPrepWindowFor(new \DateTimeImmutable(self::FRIDAY));

        $this->assertSame('2026-09-02 10:00', $start->format('Y-m-d H:i'));
    }

    /**
     * La víspera de un día 1 es el último día del mes anterior, y la del 1 de
     * enero es Nochevieja. Restar días con aritmética de fechas lo da gratis;
     * hacerlo a mano sobre el número del día, no.
     */
    public function testEvePrepCrossesTheYearBoundary(): void
    {
        $node = $this->prepNode(-1, '18:00');

        [$start] = $node->deliveryPrepWindowFor(new \DateTimeImmutable('2027-01-01'));

        $this->assertSame('2026-12-31 18:00', $start->format('Y-m-d H:i'));
    }

    /**
     * La hora la pone el punto, no la fecha de entrega que se le pase. El
     * calendario de reparto entrega objetos con hora —y a veces con la hora en
     * que se generaron—, y heredarla convocaría a la gente a horas inventadas.
     */
    public function testTheHourComesFromTheNodeAndNotFromTheDeliveryDate(): void
    {
        $node = $this->prepNode(0, '17:00');

        [$start] = $node->deliveryPrepWindowFor(new \DateTimeImmutable('2026-09-04 23:47:12'));

        $this->assertSame('2026-09-04 17:00:00', $start->format('Y-m-d H:i:s'));
    }

    /**
     * La duración da la hora de fin.
     */
    public function testTheDurationGivesTheEndOfTheWindow(): void
    {
        $node = $this->prepNode(-1, '18:00')->setDeliveryPrepMinutes(90);

        [, $end] = $node->deliveryPrepWindowFor(new \DateTimeImmutable(self::FRIDAY));

        $this->assertSame('2026-09-03 19:30', $end->format('Y-m-d H:i'));
    }

    /**
     * Sin duración no hay hora de fin, y eso es un estado válido: se puede vivir
     * con una convocatoria que dice cuándo empieza y nada más.
     */
    public function testWithoutDurationThereIsNoEnd(): void
    {
        $node = $this->prepNode(-1, '18:00');

        [, $end] = $node->deliveryPrepWindowFor(new \DateTimeImmutable(self::FRIDAY));

        $this->assertNull($end);
    }

    /**
     * Un montaje que cruza la medianoche acaba al día siguiente, sin que la
     * cuenta se rompa.
     */
    public function testAWindowCanCrossMidnight(): void
    {
        $node = $this->prepNode(-1, '23:00')->setDeliveryPrepMinutes(120);

        [$start, $end] = $node->deliveryPrepWindowFor(new \DateTimeImmutable(self::FRIDAY));

        $this->assertSame('2026-09-03 23:00', $start->format('Y-m-d H:i'));
        $this->assertSame('2026-09-04 01:00', $end->format('Y-m-d H:i'));
    }

    /**
     * Marcar el montaje sin decir la hora dejaría la convocatoria sin momento al
     * que apuntarse: una tarea publicada y vacía, que es justo lo que este
     * rediseño viene a evitar.
     */
    public function testPrepWithoutAnHourIsRejected(): void
    {
        $node = $this->node()->setDeliveryPrep(true);

        $this->assertViolationOn($node, 'deliveryPrepTime', 'hace falta saber a qué hora');
    }

    /**
     * Y sin hora tampoco hay ventana: la validación es la puerta, pero el
     * cálculo no puede fiarse de que alguien la haya cerrado.
     */
    public function testPrepWithoutAnHourHasNoWindowEither(): void
    {
        $node = $this->node()->setDeliveryPrep(true);

        $this->assertNull($node->deliveryPrepWindowFor(new \DateTimeImmutable(self::FRIDAY)));
    }

    /**
     * Apagar el montaje unos meses no obliga a borrar la configuración: al
     * volver a marcarlo sigue estando la hora y las plazas de siempre.
     */
    public function testTurningPrepOffKeepsItsSettingsWithoutComplaining(): void
    {
        $node = $this->prepNode(-1, '18:00')
            ->setDeliveryPrepSlots(4)
            ->setDeliveryPrepMinutes(90)
            ->setDeliveryPrep(false);

        $this->assertCount(0, $this->validator->validate($node));
    }

    /**
     * Montar las cestas después de repartirlas no es un caso de uso raro, es un
     * dedazo.
     */
    public function testPrepAfterTheDeliveryIsRejected(): void
    {
        $node = $this->prepNode(1, '18:00');

        $this->assertViolationOn($node, 'deliveryPrepDayOffset', 'antes de entregarlas, no después');
    }

    /**
     * "Hacen falta cero personas" no dice lo que quiere decir quien lo escribe:
     * para no poner tope, el campo se deja vacío.
     */
    public function testZeroSlotsIsRejected(): void
    {
        $node = $this->prepNode(-1, '18:00')->setDeliveryPrepSlots(0);

        $this->assertViolationOn($node, 'deliveryPrepSlots', 'al menos una persona');
    }

    /**
     * Sin tope de plazas es un estado válido y corriente: se apunta quien quiera.
     */
    public function testNoSlotCapIsValid(): void
    {
        $node = $this->prepNode(-1, '18:00')->setDeliveryPrepSlots(null);

        $this->assertCount(0, $this->validator->validate($node));
    }

    /**
     * Una duración de cero minutos daría una convocatoria que acaba cuando
     * empieza y computaría cero horas a quien fue.
     */
    public function testZeroMinutesIsRejected(): void
    {
        $node = $this->prepNode(-1, '18:00')->setDeliveryPrepMinutes(0);

        $this->assertViolationOn($node, 'deliveryPrepMinutes', 'más de cero minutos');
    }

    /**
     * La configuración completa de Torremocha, que es la que hay que poder
     * guardar: semanal, monta la víspera a las seis y media, cuatro personas,
     * hora y media.
     */
    public function testTheRealTorremochaSetupIsValid(): void
    {
        $node = $this->prepNode(-1, '18:30')
            ->setDeliveryPrepSlots(4)
            ->setDeliveryPrepMinutes(90);

        $this->assertCount(0, $this->validator->validate($node));
    }

    /**
     * Un punto semanal sin montaje con voluntariado, que es como nacen todos.
     *
     * @return Node
     */
    private function node(): Node
    {
        return (new Node())
            ->setName('Torremocha')
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_WEEKLY);
    }

    /**
     * @param int $dayOffset 0 el mismo día, -1 la víspera.
     * @param string $time Hora 'H:i' del montaje.
     * @return Node
     */
    private function prepNode(int $dayOffset, string $time): Node
    {
        return $this->node()
            ->setDeliveryPrep(true)
            ->setDeliveryPrepDayOffset($dayOffset)
            ->setDeliveryPrepTime(new \DateTimeImmutable($time));
    }

    /**
     * @param Node $node
     * @param string $path Campo en el que debe salir el error.
     * @param string $expectedFragment Trozo del mensaje que debe aparecer.
     * @return void
     */
    private function assertViolationOn(Node $node, string $path, string $expectedFragment): void
    {
        $violations = $this->validator->validate($node);

        $this->assertCount(1, $violations, 'Se esperaba exactamente una violación.');
        $this->assertSame($path, $violations[0]->getPropertyPath());
        $this->assertStringContainsString($expectedFragment, $violations[0]->getMessage());
    }
}
