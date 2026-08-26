<?php

namespace App\Tests\Entity;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use PHPUnit\Framework\TestCase;

/**
 * Qué se lleva y qué NO se lleva una copia de una tarea.
 *
 * Lo segundo es lo que importa. Repetir el reparto del viernes que viene
 * arrastrando a quien se apuntó al de la semana pasada daría por comprometida a
 * gente que no ha dicho nada, ocuparía plazas que están libres y mandaría
 * recordatorios a quien no los espera.
 */
class VolunteerOfferCopyTest extends TestCase
{
    /**
     * La copia se lleva lo que define el trabajo.
     */
    public function testLaCopiaConservaLoQueDefineElTrabajo(): void
    {
        $node = new Node();
        $category = (new VolunteerCategory())->setName('Reparto');

        $offer = $this->offer()
            ->setNode($node)
            ->setSlots(4)
            ->setCreditedMinutes(30)
            ->setCompanionsAllowed(true)
            ->setOpenToAnyone(true)
            ->addCategory($category);

        $copy = $offer->copyForDate(new \DateTime('2099-03-22 17:00'));

        $this->assertSame('Descargar el reparto', $copy->getTitle());
        $this->assertSame($node, $copy->getNode());
        $this->assertSame(4, $copy->getSlots());
        $this->assertSame(30, $copy->getCreditedMinutes());
        $this->assertTrue($copy->isCompanionsAllowed());
        $this->assertTrue($copy->isOpenToAnyone());
        $this->assertTrue($copy->getCategories()->contains($category));
    }

    /**
     * Y NO se lleva a quien se apuntó. Es el punto entero del test.
     */
    public function testLaCopiaNoArrastraAQuienSeApunto(): void
    {
        $offer = $this->offer();
        $offer->addSignup((new VolunteerSignup())->setPartner(new Partner()));

        $copy = $offer->copyForDate(new \DateTime('2099-03-22 17:00'));

        $this->assertCount(0, $copy->getSignups());
        $this->assertSame(0, $copy->getFilledSlots());
    }

    /**
     * La copia nace en borrador aunque el original estuviera publicada: doce
     * tareas creadas de golpe tienen que poder revisarse —y ajustarse los
     * festivos— antes de empezar a pedir gente solas.
     */
    public function testLaCopiaNaceEnBorrador(): void
    {
        $offer = $this->offer()->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $this->assertSame(
            VolunteerOffer::STATUS_DRAFT,
            $offer->copyForDate(new \DateTime('2099-03-22 17:00'))->getStatus()
        );
    }

    /**
     * La duración se conserva desplazando el final lo mismo que el principio.
     * Copiando `endsAt` tal cual, la copia acabaría antes de empezar.
     */
    public function testLaCopiaConservaLaDuracionYNoLaHoraDeFin(): void
    {
        $offer = $this->offer()->setEndsAt(new \DateTime('2099-03-15 19:00'));

        $copy = $offer->copyForDate(new \DateTime('2099-03-22 17:00'));

        $this->assertSame('2099-03-22 19:00', $copy->getEndsAt()->format('Y-m-d H:i'));
    }

    /**
     * Una tarea sin hora de fin produce copias sin hora de fin, no copias rotas.
     */
    public function testUnaTareaSinFinCopiaSinFin(): void
    {
        $copy = $this->offer()->copyForDate(new \DateTime('2099-03-22 17:00'));

        $this->assertNull($copy->getEndsAt());
    }

    /**
     * Queda anotado de dónde salió, para poder responder "¿por qué hay doce
     * tareas iguales?".
     */
    public function testLaCopiaRecuerdaDeDondeSalio(): void
    {
        $offer = $this->offer();

        $this->assertSame($offer, $offer->copyForDate(new \DateTime('2099-03-22 17:00'))->getCopiedFrom());
    }

    private function offer(): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStartsAt(new \DateTime('2099-03-15 17:00'))
            ->setCreatedBy(new User());
    }
}
