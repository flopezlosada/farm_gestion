<?php

namespace App\Tests\Form;

use App\Entity\Partner;
use App\Form\PartnerType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * La casilla «No es socia» de la ficha de gestión ({@see PartnerType}).
 *
 * Va al revés del dato (`member`) a propósito: el navegador no manda una casilla
 * desmarcada, y Symfony la toma por desmarcada. Un formulario que no la traiga
 * —abierto antes del despliegue— tiene que dejar la ficha como socia, no sacarla
 * de encuestas y voluntariado sin que nadie se entere.
 *
 * Se envía sólo la casilla (`clearMissing` a false) para no depender del resto de
 * la ficha; con `null` es exactamente lo que llega de una casilla sin marcar.
 */
class PartnerTypeNotMemberTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = static::getContainer()->get('form.factory');
    }

    /**
     * Envía la casilla con ese valor sobre esa ficha.
     *
     * @param string|null $notMember lo que manda el navegador: '1' marcada, null sin marcar
     */
    private function submit(Partner $partner, ?string $notMember): FormInterface
    {
        $form = $this->factory->create(PartnerType::class, $partner);
        $form->submit(['notMember' => $notMember], false);

        return $form;
    }

    public function testUnaSociaSalePintadaSinMarcar(): void
    {
        $form = $this->factory->create(PartnerType::class, (new Partner())->setName('Socia'));

        $this->assertFalse($form->get('notMember')->getData());
    }

    public function testLaFichaDeNoSociaSalePintadaMarcada(): void
    {
        $form = $this->factory->create(PartnerType::class, (new Partner())->setName('Trabajadora')->setMember(false));

        $this->assertTrue($form->get('notMember')->getData());
    }

    public function testSinMarcarSigueSiendoSocia(): void
    {
        $partner = (new Partner())->setName('Socia');

        $this->submit($partner, null);

        $this->assertTrue($partner->isMember());
    }

    public function testMarcadaDejaDeSerSocia(): void
    {
        $partner = (new Partner())->setName('Trabajadora');

        $this->submit($partner, '1');

        $this->assertFalse($partner->isMember());
    }

    /**
     * Y se puede deshacer: si se hace socia, se desmarca y vuelve a serlo.
     */
    public function testDesmarcarlaLaVuelveSocia(): void
    {
        $partner = (new Partner())->setName('Trabajadora')->setMember(false);

        $this->submit($partner, null);

        $this->assertTrue($partner->isMember());
    }
}
