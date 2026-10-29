<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\Setting;
use App\Entity\SharedBasketChangeRequest;
use App\Entity\WeeklyBasketGroup;
use App\Service\AppSettings;
use App\Service\Delivery\SharedBasketChangeMediator;
use App\Service\Delivery\SharedPairException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Las peticiones entre los dos hogares de una cesta compartida respetan el plazo de
 * autoservicio, no sólo el «día anterior» del editor.
 *
 * EL FALLO QUE CUBRE: el mediador sólo pasaba por {@see \App\Service\Delivery\SharedPairDeliveryEditor},
 * que exige que la recogida no sea hoy ni ya pasada (regla física, compartida con el
 * gestor). Entre el cierre del plazo y el día anterior, una pareja podía pedir y aceptar
 * mover o trasladar una semana cuyo listado ya había salido, y el cambio no llegaba a la
 * hoja impresa del reparto.
 *
 * Los hogares van sin punto de recogida: sin nodo, la fecha física es la del ciclo, y
 * con fechas de 2099 el plazo por defecto está siempre abierto. Para cerrarlo se guarda
 * una antelación de diez años, que deja cualquier semana fuera de plazo pero lejos de
 * su día anterior —justo el hueco que el editor no veía—.
 */
class SharedBasketRequestDeadlineTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testNoSePideMoverUnaSemanaConElPlazoCerrado(): void
    {
        $this->closeEveryDeadline();
        [$mila, $gladys] = $this->pair();
        $from = $this->basket('2099-08-14', 33);
        $to = $this->basket('2099-08-21', 34);

        $this->assertRejectedForDeadline(fn () => $this->mediator()->requestMove($mila, $from, $to));
        $this->assertNoRequestBetween($mila, $gladys);
    }

    /**
     * Control del anterior: con el plazo abierto, la petición pasa la comprobación del
     * plazo y cae en la siguiente (el otro hogar no tiene cuenta para contestar). Sin
     * esto, el test de arriba pasaría aunque cualquier petición saliera rechazada.
     */
    public function testConElPlazoAbiertoLaPeticionPasaElPlazo(): void
    {
        [$mila] = $this->pair();
        $from = $this->basket('2099-08-14', 33);
        $to = $this->basket('2099-08-21', 34);

        try {
            $this->mediator()->requestMove($mila, $from, $to);
            $this->fail('El otro hogar no tiene cuenta: la petición no puede salir.');
        } catch (SharedPairException $e) {
            $this->assertStringNotContainsString('plazo', $e->getMessage());
        }
    }

    public function testNoSePideTrasladarUnaSemanaConElPlazoCerrado(): void
    {
        $this->closeEveryDeadline();
        [$mila, $gladys] = $this->pair();
        $basket = $this->basket('2099-09-11', 37);

        $this->assertRejectedForDeadline(fn () => $this->mediator()->requestRelocate($mila, $basket, $this->group()));
        $this->assertNoRequestBetween($mila, $gladys);
    }

    /**
     * Lo que más importa: entre pedir y aceptar pasan días, y el plazo puede cerrar en
     * medio. Aceptar entonces no puede mover la cesta, y la petición sigue pendiente.
     */
    public function testAceptarUnMovimientoConElPlazoCerradoNoMueveNada(): void
    {
        [$mila, $gladys] = $this->pair();
        $from = $this->basket('2099-10-09', 41);
        $to = $this->basket('2099-10-16', 42);

        $request = new SharedBasketChangeRequest($mila, $gladys, SharedBasketChangeRequest::KIND_MOVE);
        $request->setBasket($from)->setToBasket($to);
        $this->em->persist($request);
        $this->em->flush();

        $this->closeEveryDeadline();

        $this->assertRejectedForDeadline(fn () => $this->mediator()->accept($request, $gladys));
        $this->assertTrue($request->isPending(), 'La petición sigue esperando: no se aplicó.');
        $this->assertSame(
            [],
            $this->em->getRepository(PartnerDeliveryShift::class)->findBy(['partner' => [$mila, $gladys], 'fromBasket' => $from]),
            'Ninguna de las dos mitades puede quedar movida.',
        );
    }

    public function testAceptarUnTrasladoConElPlazoCerradoNoLoAplica(): void
    {
        [$mila, $gladys] = $this->pair();
        $basket = $this->basket('2099-11-13', 46);

        $request = new SharedBasketChangeRequest($mila, $gladys, SharedBasketChangeRequest::KIND_RELOCATE);
        $request->setBasket($basket)->setTargetGroup($this->group());
        $this->em->persist($request);
        $this->em->flush();

        $this->closeEveryDeadline();

        $this->assertRejectedForDeadline(fn () => $this->mediator()->accept($request, $gladys));
        $this->assertTrue($request->isPending(), 'La petición sigue esperando: no se aplicó.');
    }

    /**
     * Comprueba que la llamada se rechaza, y que es por el plazo.
     *
     * @param callable $call La operación del mediador.
     */
    private function assertRejectedForDeadline(callable $call): void
    {
        try {
            $call();
            $this->fail('Con el plazo cerrado, la operación no debe salir adelante.');
        } catch (SharedPairException $e) {
            $this->assertStringContainsString('plazo', $e->getMessage());
        }
    }

    /**
     * Que no se guardó ninguna petición entre los dos hogares.
     *
     * @param Partner $one   Un hogar.
     * @param Partner $other El otro.
     */
    private function assertNoRequestBetween(Partner $one, Partner $other): void
    {
        $this->assertSame(
            [],
            $this->em->getRepository(SharedBasketChangeRequest::class)->findBy(['requester' => $one, 'counterpart' => $other]),
            'Una petición rechazada no puede quedarse guardada ni avisar a nadie.',
        );
    }

    /**
     * Cierra el plazo de autoservicio de todas las semanas a la vista. La fila se escribe
     * a mano porque AppSettings::setInt la recortaría a su máximo (7); al leerla no se
     * recorta. Se guarda antes de la primera lectura del mediador, que memoriza los
     * ajustes.
     */
    private function closeEveryDeadline(): void
    {
        $this->em->persist((new Setting())->setName(AppSettings::DEADLINE_DAYS_BEFORE)->setValue('3650'));
        $this->em->flush();
    }

    /**
     * @return SharedBasketChangeMediator El mediador del contenedor del test.
     */
    private function mediator(): SharedBasketChangeMediator
    {
        return static::getContainer()->get(SharedBasketChangeMediator::class);
    }

    /**
     * Pareja compartida con cesta vigente, enlazada mutuamente y con la misma modalidad
     * (lo que exige la ley L22). Mismo montaje que SharedPairMoveTest.
     *
     * @return array{0: Partner, 1: Partner} Los dos hogares.
     */
    private function pair(): array
    {
        $modality = $this->em->getRepository(BasketShare::class)->find(BasketShare::ID_MONTHLY_SHARED);
        $this->assertNotNull($modality, 'Catálogo de db_test incompleto: falta la mensual compartida.');

        $households = [];
        foreach (['MilaTest', 'GladysTest'] as $name) {
            $partner = (new Partner())->setName($name . ' ' . uniqid('', true));
            $partner->setIsActive(true);
            $this->em->persist($partner);

            // Sin encadenar: los setters heredados de esta entidad devuelven void.
            $share = new PartnerBasketShare();
            $share->setPartner($partner);
            $share->setBasketShare($modality);
            $share->setStartDate(new \DateTime('2099-01-01'));
            $share->setIsActive(true);
            $share->setDayMonthOrder(1);
            $share->setAmount(1);
            $share->setVegetablesBasketAmount(1);
            $share->setMonthPrice('0.00');
            $share->setEggMonthPrice('0.00');
            $this->em->persist($share);

            $households[] = $partner;
        }

        $households[0]->setSharePartner($households[1]);
        $households[1]->setSharePartner($households[0]);
        $this->em->flush();

        return $households;
    }

    /**
     * Una semana de reparto.
     *
     * @param string $date Viernes del ciclo.
     * @param int    $week Número de semana.
     */
    private function basket(string $date, int $week): Basket
    {
        $basket = (new Basket())->setDate(new \DateTime($date))->setWeek($week)->setAmount(1);
        $this->em->persist($basket);
        $this->em->flush();

        return $basket;
    }

    /**
     * Un punto de recogida al que trasladar.
     */
    private function group(): WeeklyBasketGroup
    {
        // `color` es NOT NULL en el esquema: el grupo se pinta en el listado.
        $group = (new WeeklyBasketGroup())->setName('PuntoTest ' . uniqid('', true));
        $group->setColor('#cccccc');
        $this->em->persist($group);
        $this->em->flush();

        return $group;
    }

    /**
     * Borra la antelación de diez años: el resto de tests cuentan con el plazo por defecto.
     */
    protected function tearDown(): void
    {
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['name' => AppSettings::DEADLINE_DAYS_BEFORE]);
        if (null !== $setting) {
            $this->em->remove($setting);
            $this->em->flush();
        }

        parent::tearDown();
    }
}
