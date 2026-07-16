<?php

namespace App\Tests\Repository;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use App\Repository\WeeklyBasketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test del finder MATERIALIZADO que alimenta el recordatorio de recogida
 * ({@see WeeklyBasketRepository::findPickedByDeliveryDateAndShares}). El
 * recordatorio es CONSCIENTE DEL NODO: ancla en la FECHA FÍSICA de entrega
 * (delivery_date), no en el viernes-ciclo del Basket. Así:
 *
 *   - cada nodo entra el día que le toca (Madrid miércoles, Torremocha viernes),
 *   - un reparto DESPLAZADO por festivo (p. ej. al jueves) se selecciona en su
 *     fecha real, no en el viernes,
 *   - quien marcó "no recojo" (status 2) NO sale,
 *   - lxs semanales (modalidad 1) NO salen,
 *   - quincenales (2) y mensuales (3) que se recogen (status 1) SÍ salen.
 *
 * Autocontenido: fechas imposibles (2099) para no colisionar con las fixtures
 * de db_test.
 */
class WeeklyBasketRepositoryTest extends KernelTestCase
{
    private const STATUS_PICKS = 1;     // "Recoge"
    private const STATUS_SKIPS = 2;     // "No la recoge"

    private const MADRID_WED = '2099-07-01';   // recogida física de Madrid
    private const SIERRA_FRI = '2099-07-03';   // recogida física de la Sierra
    private const SHIFTED_THU = '2099-07-02';  // Sierra desplazada por festivo

    public function testFinderSeleccionaPorFechaFisicaRespetandoSkipsYModalidad(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $basket = (new Basket())->setDate(new \DateTime(self::SIERRA_FRI))->setWeek(27)->setAmount(1);
        $em->persist($basket);

        $picks = $em->getRepository(WeeklyBasketStatus::class)->find(self::STATUS_PICKS);
        $skips = $em->getRepository(WeeklyBasketStatus::class)->find(self::STATUS_SKIPS);
        $biweekly = $em->getRepository(BasketShare::class)->find(BasketShare::ID_BIWEEKLY);
        $monthly = $em->getRepository(BasketShare::class)->find(BasketShare::ID_MONTHLY);
        $weekly = $em->getRepository(BasketShare::class)->find(1);
        foreach ([$picks, $skips, $biweekly, $monthly, $weekly] as $catalog) {
            $this->assertNotNull($catalog);
        }

        // Madrid: quincenal que recoge el miércoles.
        $madrid = $this->makeWeeklyBasket($em, $basket, $biweekly, $picks, self::MADRID_WED, 'WBRepo Madrid miércoles');
        // Sierra: quincenal que recoge el viernes.
        $sierra = $this->makeWeeklyBasket($em, $basket, $biweekly, $picks, self::SIERRA_FRI, 'WBRepo Sierra viernes');
        // Mensual que recoge el viernes (misma fecha que Sierra).
        $mensual = $this->makeWeeklyBasket($em, $basket, $monthly, $picks, self::SIERRA_FRI, 'WBRepo Mensual viernes');
        // Sierra desplazada por festivo al jueves.
        $desplazado = $this->makeWeeklyBasket($em, $basket, $biweekly, $picks, self::SHIFTED_THU, 'WBRepo Sierra jueves');
        // Quincenal que NO recoge el viernes (status 2): no debe salir.
        $skip = $this->makeWeeklyBasket($em, $basket, $biweekly, $skips, self::SIERRA_FRI, 'WBRepo Sierra NO recoge');
        // Semanal que recoge el viernes: no entra en el recordatorio.
        $semanal = $this->makeWeeklyBasket($em, $basket, $weekly, $picks, self::SIERRA_FRI, 'WBRepo Semanal viernes');
        $em->flush();

        /** @var WeeklyBasketRepository $repo */
        $repo = $em->getRepository(WeeklyBasket::class);
        $shares = [BasketShare::ID_BIWEEKLY, BasketShare::ID_MONTHLY];

        // El miércoles: solo Madrid.
        $wed = $this->ids($repo->findPickedByDeliveryDateAndShares(new \DateTime(self::MADRID_WED), $shares));
        $this->assertContains($madrid->getId(), $wed, 'Madrid recibe su aviso en el miércoles físico.');
        $this->assertNotContains($sierra->getId(), $wed, 'La Sierra (viernes) NO entra el miércoles.');

        // El viernes: Sierra + mensual, pero NO el desplazado, NI el skip, NI el semanal.
        $fri = $this->ids($repo->findPickedByDeliveryDateAndShares(new \DateTime(self::SIERRA_FRI), $shares));
        $this->assertContains($sierra->getId(), $fri, 'La Sierra recibe su aviso en el viernes físico.');
        $this->assertContains($mensual->getId(), $fri, 'El mensual del viernes recibe aviso.');
        $this->assertNotContains($madrid->getId(), $fri, 'Madrid (miércoles) NO entra el viernes.');
        $this->assertNotContains($desplazado->getId(), $fri, 'El reparto desplazado al jueves NO entra el viernes.');
        $this->assertNotContains($skip->getId(), $fri, 'Quien marcó "no recojo" (status 2) NO recibe aviso.');
        $this->assertNotContains($semanal->getId(), $fri, 'Lxs semanales no entran en el recordatorio.');

        // El jueves (festivo): solo el desplazado, con su fecha real.
        $thu = $this->ids($repo->findPickedByDeliveryDateAndShares(new \DateTime(self::SHIFTED_THU), $shares));
        $this->assertContains($desplazado->getId(), $thu, 'El reparto desplazado se selecciona en su jueves real.');
        $this->assertNotContains($sierra->getId(), $thu, 'La Sierra habitual (viernes) NO entra el jueves.');
    }

    public function testFinderConModalidadesVaciasDevuelveNada(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        /** @var WeeklyBasketRepository $repo */
        $repo = $em->getRepository(WeeklyBasket::class);
        $this->assertSame([], $repo->findPickedByDeliveryDateAndShares(new \DateTime(self::SIERRA_FRI), []));
    }

    /**
     * @param WeeklyBasket[] $result
     * @return int[]
     */
    private function ids(array $result): array
    {
        return array_map(static fn (WeeklyBasket $wb): int => $wb->getId(), $result);
    }

    private function makeWeeklyBasket(
        EntityManagerInterface $em,
        Basket $basket,
        BasketShare $share,
        WeeklyBasketStatus $status,
        string $deliveryDate,
        string $partnerName,
    ): WeeklyBasket {
        $partner = (new Partner())->setName($partnerName);
        $em->persist($partner);

        $wb = (new WeeklyBasket())
            ->setPartner($partner)
            ->setBasket($basket)
            ->setBasketShare($share)
            ->setWeeklyBasketStatus($status)
            ->setDeliveryDate(new \DateTime($deliveryDate))
            ->setAmount(1);
        $em->persist($wb);

        return $wb;
    }
}
