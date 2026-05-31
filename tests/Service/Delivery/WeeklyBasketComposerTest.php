<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Service\Delivery\EggDeliveryResolver;
use App\Service\Delivery\WeeklyBasketComposer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del WeeklyBasketComposer. Captura los WeeklyBasketItem persistidos
 * y verifica la composición de la entrega: verdura en cestas FÍSICAS (con el ½
 * de las compartidas y el 0 de solo-huevos ya aplicado) y huevos en docenas.
 *
 * Es la pieza que garantiza que lo materializado coincide con lo que el reparto
 * contaba antes; el test del peso por modalidad vive en BasketShareWeightTest.
 */
class WeeklyBasketComposerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EggDeliveryResolver&MockObject $eggResolver;
    private WeeklyBasketComposer $composer;
    /** @var WeeklyBasketItem[] */
    private array $persisted = [];
    private Basket $basket;

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->eggResolver = $this->createMock(EggDeliveryResolver::class);

        $vegetables = (new BasketComponent())->setName('Verdura');
        $eggs = (new BasketComponent())->setName('Huevos');

        $itemRepo = $this->createMock(ObjectRepository::class);
        // Sin ítems previos: el composer no corta por idempotencia.
        $itemRepo->method('findBy')->willReturn([]);

        $componentRepo = $this->createMock(ObjectRepository::class);
        $componentRepo->method('find')->willReturnCallback(
            static fn ($id) => $id === BasketComponent::ID_VEGETABLES ? $vegetables : $eggs
        );

        $this->em->method('getRepository')->willReturnCallback(
            static fn (string $class) => $class === WeeklyBasketItem::class ? $itemRepo : $componentRepo
        );
        $this->em->method('persist')->willReturnCallback(
            function (object $obj): void {
                if ($obj instanceof WeeklyBasketItem) {
                    $this->persisted[] = $obj;
                }
            }
        );

        $this->composer = new WeeklyBasketComposer($this->em, $this->eggResolver);
        $this->basket = (new Basket())->setDate(new \DateTime('2026-05-15'));
    }

    public function testCestaSemanalConHuevos(): void
    {
        $share = $this->shareConHuevos(1.0);
        $wb = $this->weeklyBasket(modalidad: 1, amount: 1);
        $this->eggResolver->method('delivers')->willReturn(true);

        $this->composer->compose($wb, $share, $this->basket);

        $this->assertComposicion(['Verdura' => '1.00', 'Huevos' => '1.00']);
    }

    public function testCestaSemanalSinHuevos(): void
    {
        $share = new PartnerBasketShare(); // sin egg_amount
        $wb = $this->weeklyBasket(modalidad: 1, amount: 1);
        $this->eggResolver->method('delivers')->willReturn(false);

        $this->composer->compose($wb, $share, $this->basket);

        $this->assertComposicion(['Verdura' => '1.00']);
    }

    public function testCompartidaPesaMediaCesta(): void
    {
        $share = new PartnerBasketShare();
        $wb = $this->weeklyBasket(modalidad: 4, amount: 1); // semanal compartida
        $this->eggResolver->method('delivers')->willReturn(false);

        $this->composer->compose($wb, $share, $this->basket);

        $this->assertComposicion(['Verdura' => '0.50']);
    }

    public function testSoloHuevosNoLlevaVerdura(): void
    {
        $share = $this->shareConHuevos(0.5);
        $wb = $this->weeklyBasket(modalidad: BasketShare::ID_ONLY_EGG, amount: 0);
        // isOnlyEgg ya fuerza huevos; el resolver no debe ni consultarse.
        $this->eggResolver->expects($this->never())->method('delivers');

        $this->composer->compose($wb, $share, $this->basket);

        $this->assertComposicion(['Huevos' => '0.50']);
    }

    public function testVariasCestasMantienenElPeso(): void
    {
        $share = new PartnerBasketShare();
        $wb = $this->weeklyBasket(modalidad: 1, amount: 2); // doble cesta semanal
        $this->eggResolver->method('delivers')->willReturn(false);

        $this->composer->compose($wb, $share, $this->basket);

        $this->assertComposicion(['Verdura' => '2.00']);
    }

    public function testHuevoViajaConLaCestaMovida(): void
    {
        // Entrega movida (cambio puntual): el destino NO lleva huevo por su cadencia,
        // pero el ORIGEN sí. Pasando el origen como eggReferenceBasket el huevo viaja
        // con la cesta movida (bug Etapa 2, caso Franco).
        $origen = (new Basket())->setDate(new \DateTime('2026-05-08'));
        $share = $this->shareConHuevos(1.0);
        $wb = $this->weeklyBasket(modalidad: 1, amount: 1);

        // El resolver SOLO debe consultarse contra el ORIGEN, nunca contra el destino.
        $this->eggResolver->expects($this->once())
            ->method('delivers')
            ->with($share, $origen)
            ->willReturn(true);

        $this->composer->compose($wb, $share, $this->basket, eggReferenceBasket: $origen);

        $this->assertComposicion(['Verdura' => '1.00', 'Huevos' => '1.00']);
    }

    public function testIdempotenciaNoEstampaSiYaHayItems(): void
    {
        // Repo que YA devuelve un ítem: el composer debe cortar sin persistir.
        $itemRepo = $this->createMock(ObjectRepository::class);
        $itemRepo->method('findBy')->willReturn([new WeeklyBasketItem()]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepo);
        $em->expects($this->never())->method('persist');

        $composer = new WeeklyBasketComposer($em, $this->eggResolver);
        $composer->compose($this->weeklyBasket(1, 1), new PartnerBasketShare(), $this->basket);
    }

    /**
     * Comprueba que lo persistido coincide EXACTAMENTE con el mapa esperado de
     * componente => cantidad (string decimal), sin sobrar ni faltar líneas.
     *
     * @param array<string,string> $esperado
     */
    private function assertComposicion(array $esperado): void
    {
        $real = [];
        foreach ($this->persisted as $item) {
            $real[$item->getBasketComponent()->getName()] = $item->getAmount();
        }
        $this->assertSame($esperado, $real);
    }

    private function weeklyBasket(int $modalidad, int $amount): WeeklyBasket
    {
        $bs = new BasketShare();
        $bs->setName('Modalidad de prueba');
        $bs->setId($modalidad);
        return (new WeeklyBasket())->setBasketShare($bs)->setAmount($amount);
    }

    private function shareConHuevos(float $docenas): PartnerBasketShare
    {
        $eggAmount = $this->createMock(EggAmount::class);
        $eggAmount->method('getDozens')->willReturn($docenas);
        return (new PartnerBasketShare())->setEggAmount($eggAmount);
    }
}
