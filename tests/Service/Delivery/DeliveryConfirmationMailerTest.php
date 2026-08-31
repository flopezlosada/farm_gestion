<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Cron\EffectLedger;
use App\Service\Delivery\DeliveryConfirmationMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Unit de a quién se confirma la cesta y qué se le dice.
 *
 * El caso que da sentido al aviso es el de quien pidió mover su cesta y no le
 * salió: ése NO aparece en el listado, así que un correo "sólo a quien recoge" lo
 * dejaría fuera justo a él. Aquí se comprueba que entra, y que no entra media
 * asociación con él.
 */
class DeliveryConfirmationMailerTest extends TestCase
{
    private const PICKUP = '2026-09-02';

    /**
     * Quien recoge entra y se le dice que recoge, con su fecha y su nodo.
     */
    public function testQuienRecogeEntraComoQueRecoge(): void
    {
        $node = $this->node('Madrid', 3);
        $partner = $this->partner(1, $node);

        $audience = $this->mailer([$this->weeklyBasket($partner)], [])
            ->audienceFor($node, $this->createMock(Basket::class), new \DateTimeImmutable(self::PICKUP));

        $this->assertCount(1, $audience);
        $this->assertTrue($audience[0]['picks']);
        $this->assertSame('Madrid', $audience[0]['node_name']);
        $this->assertSame(self::PICKUP, $audience[0]['pickup_date']->format('Y-m-d'));
        $this->assertFalse($audience[0]['was_shifted']);
    }

    /**
     * EL CASO QUE MOTIVA EL AVISO: quien movió su cesta fuera de esta semana no
     * sale en el listado, y aun así recibe la confirmación de que no recoge.
     */
    public function testQuienMovioSuCestaEntraComoQueNoRecoge(): void
    {
        $node = $this->node('Madrid', 3);
        $movido = $this->partner(2, $node);

        $audience = $this->mailer([], [$this->shift($movido, true)])
            ->audienceFor($node, $this->createMock(Basket::class), new \DateTimeImmutable(self::PICKUP));

        $this->assertCount(1, $audience);
        $this->assertFalse($audience[0]['picks']);
        $this->assertSame($movido, $audience[0]['partner']);
    }

    /**
     * Quien no ha tocado nada y no recoge —un quincenal fuera de turno— no recibe
     * nada: no tiene ninguna petición que confirmarle, y escribirle cada quince
     * días para decirle que no le toca es ruido.
     */
    public function testAQuienNoHaTocadoNadaNoSeLeEscribe(): void
    {
        $node = $this->node('Madrid', 3);

        $audience = $this->mailer([], [])
            ->audienceFor($node, $this->createMock(Basket::class), new \DateTimeImmutable(self::PICKUP));

        $this->assertSame([], $audience);
    }

    /**
     * Un cambio de un componente suelto (los huevos a otra semana) no saca a nadie
     * del reparto, así que no convierte a su socix en "no recoge".
     */
    public function testUnCambioDeUnComponenteNoCuentaComoNoRecoger(): void
    {
        $node = $this->node('Madrid', 3);

        $audience = $this->mailer([], [$this->shift($this->partner(3, $node), false)])
            ->audienceFor($node, $this->createMock(Basket::class), new \DateTimeImmutable(self::PICKUP));

        $this->assertSame([], $audience);
    }

    /**
     * Los movimientos se piden de todo el ciclo, así que los de OTRO nodo tienen
     * que quedarse fuera: ese nodo cerrará su plazo otro día y ya recibirá lo suyo.
     */
    public function testLosMovimientosDeOtroNodoNoEntran(): void
    {
        $madrid = $this->node('Madrid', 3);
        $sierra = $this->node('Sierra', 5);

        $audience = $this->mailer([], [$this->shift($this->partner(4, $sierra), true)])
            ->audienceFor($madrid, $this->createMock(Basket::class), new \DateTimeImmutable(self::PICKUP));

        $this->assertSame([], $audience);
    }

    /**
     * Quien recoge Y además movió algo aparece UNA vez, como que recoge: tiene
     * cesta, y es lo único que necesita saber.
     */
    public function testQuienRecogeYAdemasMovioAlgoSaleUnaSolaVez(): void
    {
        $node = $this->node('Madrid', 3);
        $partner = $this->partner(5, $node);

        $audience = $this->mailer([$this->weeklyBasket($partner)], [$this->shift($partner, true)])
            ->audienceFor($node, $this->createMock(Basket::class), new \DateTimeImmutable(self::PICKUP));

        $this->assertCount(1, $audience);
        $this->assertTrue($audience[0]['picks']);
    }

    /**
     * Una cesta extra puntual hace que el mismo socix salga dos veces del finder;
     * es una sola persona y una sola confirmación.
     */
    public function testUnaCestaExtraNoDuplicaLaConfirmacion(): void
    {
        $node = $this->node('Madrid', 3);
        $partner = $this->partner(6, $node);

        $audience = $this->mailer([$this->weeklyBasket($partner), $this->weeklyBasket($partner)], [])
            ->audienceFor($node, $this->createMock(Basket::class), new \DateTimeImmutable(self::PICKUP));

        $this->assertCount(1, $audience);
    }

    /**
     * Un reparto que no cae en el día habitual del nodo se marca como desplazado:
     * es el aviso que evita que alguien se plante el día que no era.
     */
    public function testUnRepartoFueraDeSuDiaSeMarcaComoDesplazado(): void
    {
        // El nodo reparte los viernes (5) y este reparto cae en miércoles.
        $node = $this->node('Sierra', 5);
        $partner = $this->partner(7, $node);

        $audience = $this->mailer([$this->weeklyBasket($partner)], [])
            ->audienceFor($node, $this->createMock(Basket::class), new \DateTimeImmutable(self::PICKUP));

        $this->assertTrue($audience[0]['was_shifted']);
    }

    /**
     * @param WeeklyBasket[]         $picking Cestas que devuelve el finder del nodo.
     * @param PartnerDeliveryShift[] $shifts  Movimientos salientes del ciclo.
     */
    private function mailer(array $picking, array $shifts): DeliveryConfirmationMailer
    {
        $weeklyBaskets = $this->createMock(WeeklyBasketRepository::class);
        $weeklyBaskets->method('findForNodeAndBasket')->willReturn($picking);

        $shiftRepository = $this->createMock(PartnerDeliveryShiftRepository::class);
        $shiftRepository->method('findAllOutgoingFromBasket')->willReturn($shifts);

        return new DeliveryConfirmationMailer(
            $this->createMock(MailerInterface::class),
            $weeklyBaskets,
            $shiftRepository,
            $this->createMock(EffectLedger::class),
        );
    }

    /**
     * @param string $name    Nombre del nodo.
     * @param int    $weekday Día de la semana en que reparte (ISO, 1=lunes).
     */
    private function node(string $name, int $weekday): Node
    {
        $node = $this->createMock(Node::class);
        $node->method('getName')->willReturn($name);
        $node->method('getDeliveryWeekday')->willReturn($weekday);

        return $node;
    }

    /**
     * @param int  $id   Identificador, que es lo que deduplica la audiencia.
     * @param Node $node Nodo al que pertenece por su grupo de recogida.
     */
    private function partner(int $id, Node $node): Partner
    {
        $group = $this->createMock(WeeklyBasketGroup::class);
        $group->method('getNode')->willReturn($node);

        $partner = $this->createMock(Partner::class);
        $partner->method('getId')->willReturn($id);
        $partner->method('getWeeklyBasketGroup')->willReturn($group);

        return $partner;
    }

    /**
     * @param Partner $partner Socix de la cesta.
     */
    private function weeklyBasket(Partner $partner): WeeklyBasket
    {
        $weeklyBasket = $this->createMock(WeeklyBasket::class);
        $weeklyBasket->method('getPartner')->willReturn($partner);

        return $weeklyBasket;
    }

    /**
     * @param Partner $partner Socix que pidió el cambio.
     * @param bool    $whole   Si mueve la cesta entera o sólo un componente.
     */
    private function shift(Partner $partner, bool $whole): PartnerDeliveryShift
    {
        $shift = $this->createMock(PartnerDeliveryShift::class);
        $shift->method('getPartner')->willReturn($partner);
        $shift->method('isWholeDelivery')->willReturn($whole);

        return $shift;
    }
}
