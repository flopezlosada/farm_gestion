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

    public function testAmountForComponentVerduraUsaElPesoDeLaModalidadDelShare(): void
    {
        $veg = $this->component(BasketComponent::ID_VEGETABLES, 'Verdura');

        // Mensual (peso 1.0) → 1 cesta; compartida (0.5) → media; solo-huevo (0) → nada.
        $this->assertSame('1.00', $this->composer->amountForComponent($this->pbs(3), $veg));
        $this->assertSame('0.50', $this->composer->amountForComponent($this->pbs(4), $veg));
        $this->assertNull($this->composer->amountForComponent($this->pbs(BasketShare::ID_ONLY_EGG), $veg));
    }

    public function testAmountForComponentHuevosEnDocenas(): void
    {
        $eggs = $this->component(BasketComponent::ID_EGGS, 'Huevos');

        $this->assertSame('1.50', $this->composer->amountForComponent($this->pbs(3, 1.5), $eggs));
        // Sin huevos contratados → no aplica.
        $this->assertNull($this->composer->amountForComponent($this->pbs(3), $eggs));
    }

    public function testAddComponentEstampaConCantidadDelShareNoDelWb(): void
    {
        // CLAVE del caso SANTOS: la verdura aterriza en un WB "solo-huevo" (peso 0),
        // pero su cantidad sale del SHARE mensual del socio (peso 1), no del WB.
        $veg = $this->component(BasketComponent::ID_VEGETABLES, 'Verdura');
        $wbSoloHuevo = $this->weeklyBasket(modalidad: BasketShare::ID_ONLY_EGG, amount: 0);

        $this->composer->addComponent($wbSoloHuevo, $this->pbs(3), $veg);

        $this->assertComposicion(['Verdura' => '1.00']);
    }

    public function testAddComponentEsIdempotente(): void
    {
        $veg = $this->component(BasketComponent::ID_VEGETABLES, 'Verdura');
        $itemRepo = $this->createMock(ObjectRepository::class);
        $itemRepo->method('findBy')->willReturn([new WeeklyBasketItem()]); // ya hay línea
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepo);
        $em->expects($this->never())->method('persist');

        $composer = new WeeklyBasketComposer($em, $this->eggResolver);
        $composer->addComponent(new WeeklyBasket(), $this->pbs(3), $veg);
    }

    public function testRemoveComponentQuitaLaLinea(): void
    {
        $veg = $this->component(BasketComponent::ID_VEGETABLES, 'Verdura');
        $item = new WeeklyBasketItem();
        $itemRepo = $this->createMock(ObjectRepository::class);
        $itemRepo->method('findBy')->willReturn([$item]);

        $removed = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepo);
        $em->method('remove')->willReturnCallback(function (object $o) use (&$removed): void {
            $removed[] = $o;
        });

        $composer = new WeeklyBasketComposer($em, $this->eggResolver);
        $composer->removeComponent(new WeeklyBasket(), $veg);

        $this->assertSame([$item], $removed);
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

    /**
     * Componente con id real (BasketComponent no expone setId): se mockea para que
     * amountForComponent/addComponent comparen contra ID_VEGETABLES / ID_EGGS.
     */
    private function component(int $id, string $name): BasketComponent
    {
        $c = $this->createMock(BasketComponent::class);
        $c->method('getId')->willReturn($id);
        $c->method('getName')->willReturn($name);
        return $c;
    }

    /**
     * PartnerBasketShare con una modalidad (para el peso de verdura) y, opcional,
     * huevos en docenas. setBasketShare/setId devuelven void → sin encadenar.
     */
    private function pbs(int $modalidad, ?float $docenas = null): PartnerBasketShare
    {
        $bs = new BasketShare();
        $bs->setId($modalidad);
        $share = new PartnerBasketShare();
        $share->setBasketShare($bs);
        $share->setAmount(1);
        if ($docenas !== null) {
            $eggAmount = $this->createMock(EggAmount::class);
            $eggAmount->method('getDozens')->willReturn($docenas);
            $share->setEggAmount($eggAmount);
        }
        return $share;
    }
}
