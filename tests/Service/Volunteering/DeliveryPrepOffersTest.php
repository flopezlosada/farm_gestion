<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Repository\BasketRepository;
use App\Repository\VolunteerOfferRepository;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Volunteering\DeliveryPrepOffers;
use App\Service\Volunteering\ShiftGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * La convocatoria de montar las cestas, creada y mantenida desde el punto de
 * recogida.
 *
 * Lo que se blinda aquí es el reparto de gobierno, que es donde está el riesgo:
 * el punto manda sobre CUÁNDO y CUÁNTA GENTE, y quien gestiona manda sobre lo
 * editorial. Si el sync pisara el título o las áreas, cada pasada del cron
 * borraría el trabajo de una persona; y si NO reescribiera la receta, cambiar la
 * hora en el punto no llegaría a los turnos y la tarjeta «quién prepara tu
 * cesta» acabaría buscando donde no hay nadie.
 */
class DeliveryPrepOffersTest extends TestCase
{
    private const NOW = '2026-09-07 08:00';

    /**
     * Un punto que empieza a montar con voluntariado tiene convocatoria y turnos
     * sin que nadie los cree.
     */
    public function testUnPuntoQueMontaConVoluntariadoEstrenaConvocatoria(): void
    {
        $node = $this->node();

        $result = $this->service()->sync($node, $this->now());
        $offer = $result['offer'];

        $this->assertInstanceOf(VolunteerOffer::class, $offer);
        $this->assertSame('Montaje de cestas · Torremocha', $offer->getTitle());
        $this->assertSame(VolunteerOffer::REPEAT_DELIVERY, $offer->getRepeatType());
        $this->assertSame($node, $offer->getNode());
        $this->assertTrue($offer->isDeliveryPrep());
    }

    /**
     * NACE EN BORRADOR, y no es un descuido: publicarla sola convocaría a gente a
     * una tarea sin explicar y sin área — y sin área no le llega el aviso a
     * nadie, porque los avisos van por área.
     */
    public function testLaConvocatoriaNaceEnBorrador(): void
    {
        $offer = $this->service()->sync($this->node(), $this->now())['offer'];

        $this->assertSame(VolunteerOffer::STATUS_DRAFT, $offer->getStatus());
    }

    /**
     * La receta sale entera del punto: la hora, el desfase de la víspera y las
     * plazas. Sin fecha final, porque el montaje no se acaba en diciembre.
     */
    public function testLaRecetaSaleDelPunto(): void
    {
        $offer = $this->service()->sync($this->node(), $this->now())['offer'];

        $this->assertSame([['18:30', '20:00']], $offer->getRepeatTimes());
        $this->assertSame(-1, $offer->getRepeatOffsetDays());
        $this->assertSame(4, $offer->getSlots());
        $this->assertNull($offer->getRepeatUntil());
    }

    /**
     * Y con día de arranque SIEMPRE. Sin él, ShiftGenerator::window() devuelve
     * null y el sync no crea nada, no retira nada y no se queja: una convocatoria
     * sin turnos y sin pista de por qué.
     */
    public function testLaConvocatoriaNaceConDiaDeArranque(): void
    {
        $offer = $this->service()->sync($this->node(), $this->now())['offer'];

        $this->assertSame('2026-09-07', $offer->getRepeatFrom()?->format('Y-m-d'));
    }

    /**
     * Cambiar la hora en el punto llega a la convocatoria que ya existía. Es la
     * razón de que el punto gobierne: si la tarea pudiera desviarse, la ventana
     * que busca quién montó y los turnos reales dejarían de coincidir.
     */
    public function testCambiarLaHoraEnElPuntoReescribeLaReceta(): void
    {
        $node = $this->node();
        $existing = $this->existingOffer($node);

        $node->setDeliveryPrepTime(new \DateTimeImmutable('17:00'))
            ->setDeliveryPrepMinutes(60)
            ->setDeliveryPrepSlots(6);

        $result = $this->service($existing)->sync($node, $this->now());

        $this->assertSame($existing, $result['offer'], 'No debe crear una segunda convocatoria.');
        $this->assertSame([['17:00', '18:00']], $existing->getRepeatTimes());
        $this->assertSame(6, $existing->getSlots());
    }

    /**
     * Lo editorial es de quien gestiona y el sync no lo toca: el título que
     * alguien reescribió, la explicación y las áreas siguen ahí después de cada
     * pasada del cron.
     */
    public function testElSyncNoPisaLoEditorial(): void
    {
        $node = $this->node();
        $existing = $this->existingOffer($node)
            ->setTitle('Montar las cestas del viernes')
            ->setDescription('Nos vemos en la nave, traed guantes.');
        $existing->addCategory((new VolunteerCategory())->setName('Reparto'));

        $this->service($existing)->sync($node, $this->now());

        $this->assertSame('Montar las cestas del viernes', $existing->getTitle());
        $this->assertSame('Nos vemos en la nave, traed guantes.', $existing->getDescription());
        $this->assertCount(1, $existing->getCategories());
    }

    /**
     * Apagar el montaje PAUSA la convocatoria, no la borra: puede tener gente
     * apuntada y guarda quién montó qué semana, que es historia de la asociación
     * y no configuración.
     */
    public function testApagarElMontajePausaLaConvocatoria(): void
    {
        $node = $this->node()->setDeliveryPrep(false);
        $existing = $this->existingOffer($node)->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $result = $this->service($existing)->sync($node, $this->now());

        $this->assertSame(VolunteerOffer::STATUS_PAUSED, $existing->getStatus());
        $this->assertSame($existing, $result['offer']);
    }

    /**
     * Un punto que nunca ha montado con voluntariado no estrena nada por el hecho
     * de guardarlo, que es el caso de tres de los cuatro puntos.
     */
    public function testUnPuntoQueNoMontaNoEstrenaNada(): void
    {
        $result = $this->service()->sync($this->node()->setDeliveryPrep(false), $this->now());

        $this->assertNull($result['offer']);
    }

    /**
     * Marcado sin hora no crea una convocatoria muda. No debería llegar aquí
     * —lo impide la validación del punto—, pero el servicio no se fía: sin hora
     * la receta no dicta ningún turno.
     */
    public function testMarcadoSinHoraNoCreaNada(): void
    {
        $result = $this->service()->sync($this->node()->setDeliveryPrepTime(null), $this->now());

        $this->assertNull($result['offer']);
    }

    /**
     * Los turnos salen del calendario de reparto y con el desfase puesto: el
     * reparto es el viernes 11 y el montaje, el jueves 10 a las seis y media.
     */
    public function testLosTurnosCaenLaVisperaDeCadaReparto(): void
    {
        $result = $this->service()->sync($this->node(), $this->now());

        $this->assertSame(
            ['2026-09-10 18:30'],
            array_map(static fn (VolunteerShift $s): string => $s->getStartsAt()->format('Y-m-d H:i'), $result['created'])
        );
    }

    /**
     * Torremocha: monta la víspera a las seis y media, cuatro personas, hora y
     * media.
     */
    private function node(): Node
    {
        return (new Node())
            ->setName('Torremocha')
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_WEEKLY)
            ->setDeliveryPrep(true)
            ->setDeliveryPrepDayOffset(-1)
            ->setDeliveryPrepTime(new \DateTimeImmutable('18:30'))
            ->setDeliveryPrepSlots(4)
            ->setDeliveryPrepMinutes(90);
    }

    /**
     * Una convocatoria que ya existe, con la receta de una configuración
     * anterior, para comprobar qué se reescribe y qué no.
     */
    private function existingOffer(Node $node): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Montaje de cestas · Torremocha')
            ->setStatus(VolunteerOffer::STATUS_DRAFT)
            ->setDeliveryPrep(true)
            ->setNode($node)
            ->setRepeatType(VolunteerOffer::REPEAT_DELIVERY)
            ->setRepeatFrom(new \DateTimeImmutable('2026-01-01'))
            ->setRepeatTimes([['18:30', '20:00']])
            ->setRepeatOffsetDays(-1)
            ->setSlots(4);
    }

    /**
     * El servicio con un calendario que resuelve un solo reparto, el viernes 11.
     *
     * @param VolunteerOffer|null $existing la convocatoria que el repositorio encuentra, si la hay
     */
    private function service(?VolunteerOffer $existing = null): DeliveryPrepOffers
    {
        $offers = $this->createMock(VolunteerOfferRepository::class);
        $offers->method('findDeliveryPrepOffer')->willReturn($existing);

        $baskets = $this->createMock(BasketRepository::class);
        $baskets->method('findBetweenDates')->willReturn([new Basket()]);

        $calendar = $this->createMock(NodeDeliveryDate::class);
        $calendar->method('physicalDateFor')->willReturn(new \DateTimeImmutable('2026-09-11'));

        $em = $this->createMock(EntityManagerInterface::class);

        return new DeliveryPrepOffers(
            $em,
            $offers,
            new ShiftGenerator($em, $baskets, $calendar),
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
