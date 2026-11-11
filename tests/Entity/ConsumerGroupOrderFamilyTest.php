<?php

namespace App\Tests\Entity;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupRound;
use App\Entity\Partner;
use PHPUnit\Framework\TestCase;

/**
 * El pedido del grupo de consumo es de la familia: lo haga quien lo haga, queda a
 * nombre de quien la encabeza, que es lo que impide que dos miembros pidan por
 * duplicado en la misma ronda.
 */
class ConsumerGroupOrderFamilyTest extends TestCase
{
    public function testElPedidoDeUnMiembroQuedaANombreDeQuienEncabeza(): void
    {
        $head = (new Partner())->setname('Ana');
        $relative = (new Partner())->setname('Pablo');
        $head->addRelative($relative);

        $order = new ConsumerGroupOrder(new ConsumerGroupRound(), $relative);

        self::assertSame($head, $order->getPartner());
    }

    public function testCambiarLaSociaTambienVaAQuienEncabeza(): void
    {
        $head = new Partner();
        $relative = new Partner();
        $head->addRelative($relative);

        $order = (new ConsumerGroupOrder(new ConsumerGroupRound()))->setPartner($relative);

        self::assertSame($head, $order->getPartner());
    }

    public function testQuienViveSolaEsSuPropiaFamilia(): void
    {
        $alone = new Partner();

        self::assertSame($alone, (new ConsumerGroupOrder(new ConsumerGroupRound(), $alone))->getPartner());
        self::assertSame([$alone], $alone->getFamilyMembers());
    }

    public function testLaFamiliaEmpiezaPorQuienLaEncabezaVistaDesdeCualquierMiembro(): void
    {
        $head = new Partner();
        $pablo = new Partner();
        $lucia = new Partner();
        $head->addRelative($pablo);
        $head->addRelative($lucia);

        self::assertSame([$head, $pablo, $lucia], $pablo->getFamilyMembers());
        self::assertSame([$head, $pablo, $lucia], $head->getFamilyMembers());
    }

    public function testSinSociaElPedidoSigueSinSocia(): void
    {
        self::assertNull((new ConsumerGroupOrder(new ConsumerGroupRound()))->setPartner(null)->getPartner());
    }
}
