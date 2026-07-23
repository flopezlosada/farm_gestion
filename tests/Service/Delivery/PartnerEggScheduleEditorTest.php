<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Service\Delivery\EggScheduleException;
use App\Service\Delivery\PartnerEggScheduleEditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guardas del editor de huevos compartido por el gestor y el panel del socio. Cubre las
 * invariantes tempranas y deterministas (sin depender de nodo/cadencia): destino ausente y
 * socio sin cesta activa. El happy-path (mover huevos sin tocar la cesta) lo cubre el motor
 * en {@see SharedEggMoveTest}; el flujo completo controlador→handler, el test funcional.
 */
class PartnerEggScheduleEditorTest extends KernelTestCase
{
    private const SHARE_BIWEEKLY_SHARED = 6;

    private EntityManagerInterface $em;
    private PartnerEggScheduleEditor $editor;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->editor = static::getContainer()->get(PartnerEggScheduleEditor::class);
    }

    public function testLanzaSiNoSeEligeFechaDestino(): void
    {
        $partner = $this->partnerWithActiveShare();
        $from = $this->futureBasket('2099-09-04', 36);
        $this->em->flush();

        $this->expectException(EggScheduleException::class);
        $this->expectExceptionMessage('Selecciona una fecha destino válida.');
        $this->editor->move($partner, $from, null, 'test');
    }

    public function testLanzaSiElSocioNoTieneCestaActiva(): void
    {
        $partner = (new Partner())->setName('SinCesta ' . uniqid('', true));
        $this->em->persist($partner);
        $from = $this->futureBasket('2099-09-11', 37);
        $to = $this->futureBasket('2099-09-25', 39);
        $this->em->flush();

        $this->expectException(EggScheduleException::class);
        $this->expectExceptionMessage('No hay una cesta activa esa semana.');
        $this->editor->move($partner, $from, $to, 'test');
    }

    private function partnerWithActiveShare(): Partner
    {
        $partner = (new Partner())->setName('ConCesta ' . uniqid('', true));
        $this->em->persist($partner);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($this->em->getRepository(BasketShare::class)->find(self::SHARE_BIWEEKLY_SHARED));
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setVegetablesBasketAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        $share->setStartDate(new \DateTime('2020-01-01'));
        $this->em->persist($share);

        return $partner;
    }

    private function futureBasket(string $date, int $week): Basket
    {
        $basket = (new Basket())->setDate(new \DateTime($date))->setWeek($week)->setAmount(1);
        $this->em->persist($basket);

        return $basket;
    }
}
