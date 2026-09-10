<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\EmittedEffect;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use App\Repository\EmittedEffectRepository;
use App\Service\Delivery\PickupNoticeCoverage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La comprobación de cobertura del recordatorio.
 *
 * Lo que se protege aquí es que sepa encontrar EL FALLO QUE NADIE VIO: durante
 * dos meses las cestas compartidas se quedaron sin aviso y el sistema no dio
 * ninguna señal, porque la tarea hacía lo que le habían pedido y lo hacía bien.
 * El caso central de este test es exactamente ése — gente que recoge, que
 * puede recibir el aviso, y de la que no consta ningún envío — y tiene que
 * salir como hueco.
 *
 * Los otros dos casos son los que evitan que la cifra se vuelva ruido: quien no
 * tiene por dónde recibir nada no es un hueco, y las modalidades que no reciben
 * aviso por diseño tampoco.
 *
 * Autocontenido: fechas de 2099 para no cruzarse con las fixtures.
 */
class PickupNoticeCoverageTest extends KernelTestCase
{
    /**
     * Fechas EXCLUSIVAS de este test. No es capricho: la cobertura cuenta todo
     * lo que se recoge ese día, así que una cesta sembrada por otro test en la
     * misma fecha entra en el recuento y rompe la comprobación. Antes de tocar
     * estas constantes, comprobar que nadie más las usa.
     */
    private const DATE = '2099-05-08';

    /** @var list<array{class-string, int|null}> lo sembrado, para deshacerlo al terminar */
    private array $sembrado = [];

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // En orden inverso —las cestas antes que sus socixs y su reparto— y
        // releyendo por id: el objeto sembrado puede haberse quedado desligado,
        // y `remove()` sobre una entidad desligada revienta.
        foreach (array_reverse($this->sembrado) as [$clase, $id]) {
            if ($id === null) {
                continue;
            }
            $entidad = $em->find($clase, $id);
            if ($entidad !== null) {
                $em->remove($entidad);
                $em->flush();
            }
        }
        $this->sembrado = [];

        parent::tearDown();
    }

    /**
     * EL CASO QUE JUSTIFICA LA PANTALLA: alguien con cesta quincenal
     * compartida, con correo y sin nada silenciado, del que no consta aviso.
     * Sale como hueco y con nombre.
     */
    public function testQuienPodiaRecibirElAvisoYNoLoRecibioSaleComoHueco(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $olvidada = $this->sembrarCesta($em, BasketShare::ID_BIWEEKLY_SHARED, 'OlvidadaSocix', 'olvidada@test.org');
        $avisada = $this->sembrarCesta($em, BasketShare::ID_BIWEEKLY, 'AvisadaSocix', 'avisada@test.org');
        $em->flush();

        $this->sembrarAviso($em, $avisada->getPartner());
        $em->flush();

        $cobertura = self::getContainer()->get(PickupNoticeCoverage::class)->forDate(new \DateTimeImmutable(self::DATE));

        $this->assertSame(1, $cobertura['totals']['gap'], 'Quien podía recibirlo y no consta avisadx es un hueco.');
        $this->assertSame(1, $cobertura['totals']['notified']);
        $this->assertCount(1, $cobertura['missing']);
        $this->assertSame('OlvidadaSocix', $cobertura['missing'][0]->getName());
    }

    /**
     * Sin correo y sin cuenta no hay por dónde avisar: eso es una ficha a
     * medias, no un fallo del sistema, y se cuenta aparte. Si entrara en el
     * hueco, la cifra que importa no bajaría nunca a cero y dejaría de mirarse.
     */
    public function testQuienNoTienePorDondeRecibirloNoCuentaComoHueco(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->sembrarCesta($em, BasketShare::ID_BIWEEKLY, 'SinCorreoSocix', null);
        $em->flush();

        $cobertura = self::getContainer()->get(PickupNoticeCoverage::class)->forDate(new \DateTimeImmutable(self::DATE));

        $this->assertSame(0, $cobertura['totals']['gap']);
        $this->assertSame(1, $cobertura['totals']['unreachable']);
        $this->assertSame([], $cobertura['missing']);
    }

    /**
     * Las semanales no reciben recordatorio a propósito, así que no son hueco.
     * Pero SÍ salen en el desglose, marcadas como fuera de alcance: es lo que
     * haría visible que alguien sacara una modalidad de la lista sin querer.
     */
    public function testLasModalidadesSinAvisoSalenPeroNoCuentanComoHueco(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->sembrarCesta($em, BasketShare::ID_WEEKLY, 'SemanalSocix', 'semanal@test.org');
        $em->flush();

        $cobertura = self::getContainer()->get(PickupNoticeCoverage::class)->forDate(new \DateTimeImmutable(self::DATE));

        $this->assertSame(0, $cobertura['totals']['gap'], 'Una semanal sin aviso es lo correcto.');
        $this->assertSame(1, $cobertura['totals']['picking']);

        $fila = $cobertura['rows'][0] ?? null;
        $this->assertNotNull($fila, 'La modalidad tiene que salir en el desglose aunque no reciba aviso.');
        $this->assertFalse($fila['in_scope']);
    }

    /**
     * La comprobación NO puede preguntar con el mismo criterio que usa el
     * recordatorio para elegir: si lo hiciera, una modalidad olvidada quedaría
     * fuera de las dos consultas y saldría todo en verde. Aquí se comprueba que
     * el recuento parte de TODO el que recoge.
     */
    public function testCuentaATodoElQueRecogeAunqueSuModalidadNoRecibaAviso(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->sembrarCesta($em, BasketShare::ID_WEEKLY, 'SemanalSocix', 'semanal@test.org');
        $this->sembrarCesta($em, BasketShare::ID_MONTHLY_SHARED, 'MensualCompartidaSocix', 'mensualc@test.org');
        $em->flush();

        $cobertura = self::getContainer()->get(PickupNoticeCoverage::class)->forDate(new \DateTimeImmutable(self::DATE));

        $this->assertSame(2, $cobertura['totals']['picking'], 'Se cuentan las dos, reciban aviso o no.');
        $this->assertCount(2, $cobertura['rows']);
    }

    /**
     * De un reparto anterior al primer apunte no se afirma nada: entonces se
     * avisaba igual y no quedaba constancia.
     *
     * Sin esta guarda, la pantalla acusaría al sistema de haber dejado sin
     * avisar a media asociación cada vez que alguien mira un reparto viejo, y
     * un indicador que grita cuando no pasa nada deja de mirarse.
     */
    public function testUnRepartoAnteriorAlRegistroNoCuentaHuecos(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $effects = self::getContainer()->get(EmittedEffectRepository::class);
        $coverage = self::getContainer()->get(PickupNoticeCoverage::class);

        // La frontera se DERIVA del apunte más antiguo que haya en la base, en
        // vez de fijar una fecha a mano: otros tests dejan apuntes suyos, y con
        // una constante bastaría uno anterior para que este caso dejara de ser
        // "antes del registro" y la comprobación midiera otra cosa.
        $frontera = $effects->earliestOccurredOn(PickupNoticeCoverage::noticeKinds());
        $viejo = ($frontera ?? new \DateTimeImmutable(self::DATE))->modify('-1 day');

        $basket = (new Basket())->setDate(\DateTime::createFromInterface($viejo))->setWeek(1)->setAmount(1);
        $em->persist($basket);
        $partner = (new Partner())->setName('AntiguaSocix')->setSurname('Prueba ' . uniqid());
        $partner->setEmail(uniqid() . '-antigua@test.org');
        $em->persist($partner);
        $wb = (new WeeklyBasket())
            ->setPartner($partner)
            ->setBasket($basket)
            ->setBasketShare($em->getRepository(BasketShare::class)->find(BasketShare::ID_BIWEEKLY))
            ->setWeeklyBasketStatus($em->getRepository(WeeklyBasketStatus::class)->find(1))
            ->setDeliveryDate(\DateTime::createFromInterface($viejo))
            ->setAmount(1);
        $em->persist($wb);
        $em->flush();
        $this->sembrado[] = [Basket::class, $basket->getId()];
        $this->sembrado[] = [Partner::class, $partner->getId()];
        $this->sembrado[] = [WeeklyBasket::class, $wb->getId()];

        $cobertura = $coverage->forDate($viejo);

        $this->assertTrue($cobertura['unrecorded'], 'Antes del primer apunte no hay nada que comprobar.');
        $this->assertSame(0, $cobertura['totals']['gap'], 'No se acusa de huecos donde no hay registro.');
        $this->assertSame([], $cobertura['missing']);
        // El recuento de quién recogía sí se sabe. No se compara con un número
        // exacto: ese día puede haber cestas de las fixtures.
        $this->assertGreaterThanOrEqual(1, $cobertura['totals']['picking'], 'Sí se sabe quién recogía.');
    }

    /**
     * Siembra una cesta que se recoge ese día.
     *
     * @param string|null $email null = ficha sin correo
     */
    private function sembrarCesta(EntityManagerInterface $em, int $shareId, string $nombre, ?string $email): WeeklyBasket
    {
        // El correo lleva sufijo único: la columna tiene índice único y varios
        // tests siembran la misma persona de mentira.
        $email = $email === null ? null : uniqid() . '-' . $email;

        $basket = (new Basket())->setDate(new \DateTime(self::DATE))->setWeek(45)->setAmount(1);
        $em->persist($basket);

        $partner = (new Partner())->setName($nombre)->setSurname('Prueba ' . uniqid());
        if ($email !== null) {
            $partner->setEmail($email);
        }
        $em->persist($partner);

        $wb = (new WeeklyBasket())
            ->setPartner($partner)
            ->setBasket($basket)
            ->setBasketShare($em->getRepository(BasketShare::class)->find($shareId))
            ->setWeeklyBasketStatus($em->getRepository(WeeklyBasketStatus::class)->find(1))
            ->setDeliveryDate(new \DateTime(self::DATE))
            ->setAmount(1);
        $em->persist($wb);
        $em->flush();

        // Se apunta DESPUÉS del flush (antes no hay id con el que releerlo) y
        // en orden de creación: la limpieza los recorre al revés, así que la
        // cesta se borra antes que el reparto y la persona de los que cuelga.
        $this->sembrado[] = [Basket::class, $basket->getId()];
        $this->sembrado[] = [Partner::class, $partner->getId()];
        $this->sembrado[] = [WeeklyBasket::class, $wb->getId()];

        return $wb;
    }

    /** Deja constancia de que a esa persona se le avisó de este reparto. */
    private function sembrarAviso(EntityManagerInterface $em, Partner $partner): void
    {
        $efecto = (new EmittedEffect())
            ->setKind('pickup_reminder')
            ->setReference(sprintf('partner-%d', $partner->getId()))
            ->setOccurredOn(new \DateTimeImmutable(self::DATE))
            ->setTarget((string) $partner->getEmail());
        $em->persist($efecto);
        $em->flush();
        $this->sembrado[] = [EmittedEffect::class, $efecto->getId()];
    }
}
