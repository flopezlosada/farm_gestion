<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Service\Delivery\DeliveryShiftApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "No recoge" una semana deja el día a CERO, cestas extra incluidas.
 *
 * Regresión del lío de tres pasos que reportó administración: trasladar una cesta SUMANDO a
 * un día que ya recoge (ese día pasa a llevar 2 cestas) y marcar después "no recoge" solo
 * quitaba una — el override de extra sobrevivía al skip y la proyección lo resucitaba. El día
 * quedaba con una cesta fantasma que además no se podía mover (su día ya tenía el skip
 * saliente), así que no había salida.
 *
 * Se prueba contra el servicio del CONTENEDOR a propósito: así el test cubre también que
 * Symfony resuelve la interfaz {@see \App\Service\Delivery\ExtraBasketRemover} a su único
 * implementador, que es de lo que depende el applier.
 */
class SkipClearsBasketExtraTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Aparcar la entrega ENTERA de una semana borra sus overrides de cesta extra.
     */
    public function testApplySkipIntentQuitaLaCestaExtraDeEsaSemana(): void
    {
        self::bootKernel();
        $em = $this->em();

        $vegetables = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES);
        $this->assertNotNull($vegetables);

        $partner = (new Partner())->setName('SkipExtra ' . uniqid('', true));
        $em->persist($partner);

        $basket = (new Basket())->setDate(new \DateTime('2099-10-02'))->setWeek(40)->setAmount(1);
        $em->persist($basket);

        // El día lleva una cesta extra (la que dejaría un "trasladar sumando").
        $em->persist(new PartnerBasketExtra($partner, $basket, $vegetables, '1.00'));
        $em->flush();

        static::getContainer()->get(DeliveryShiftApplier::class)
            ->applySkipIntent($partner, $basket, null, 'test:skip-extra');

        $em->clear();

        $partner = $em->getRepository(Partner::class)->find($partner->getId());
        $basket = $em->getRepository(Basket::class)->find($basket->getId());

        $this->assertSame(
            [],
            $em->getRepository(PartnerBasketExtra::class)->findForPartnerAndBasket($partner, $basket),
            'El "no recoge" de la entrega entera debe dejar el día a cero: sin cestas extra.',
        );

        $skip = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partner, 'fromBasket' => $basket]);
        $this->assertNotNull($skip, 'Debe quedar el intent de "no recoge" (la cesta aparcada).');
        $this->assertTrue($skip->isSkip(), 'El intent es un skip (sin destino).');

        $this->cleanUp($em, $partner, $basket);
    }

    /**
     * Quitar UN COMPONENTE (no la entrega entera) NO toca las cestas extra: el día sigue
     * teniendo entrega, así que el añadido puntual sigue siendo suyo.
     */
    public function testQuitarUnComponenteNoTocaLaCestaExtra(): void
    {
        self::bootKernel();
        $em = $this->em();

        $eggs = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_EGGS);
        $vegetables = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES);
        $this->assertNotNull($eggs);
        $this->assertNotNull($vegetables);

        $partner = (new Partner())->setName('SkipComp ' . uniqid('', true));
        $em->persist($partner);

        $basket = (new Basket())->setDate(new \DateTime('2099-10-09'))->setWeek(41)->setAmount(1);
        $em->persist($basket);

        $em->persist(new PartnerBasketExtra($partner, $basket, $vegetables, '1.00'));
        $em->flush();

        // Intent de "quitar los huevos" de esa semana (component != null).
        static::getContainer()->get(DeliveryShiftApplier::class)
            ->applySkipIntent($partner, $basket, $eggs, 'test:skip-component');

        $em->clear();

        $partner = $em->getRepository(Partner::class)->find($partner->getId());
        $basket = $em->getRepository(Basket::class)->find($basket->getId());

        $this->assertCount(
            1,
            $em->getRepository(PartnerBasketExtra::class)->findForPartnerAndBasket($partner, $basket),
            'Quitar un componente no vacía el día: la cesta extra se queda.',
        );

        $this->cleanUp($em, $partner, $basket);
    }

    /**
     * Vaciar un día al que OTRA semana había trasladado su cesta sumando devuelve esa cesta
     * a PENDIENTE (papelera), en vez de dejarla apuntando a una entrega que ya no existe.
     *
     * Sin esto la cesta desaparecía: su semana de origen está vaciada por el intent y el
     * destino ya no la lleva — ni entregada ni recuperable. Es el caso "trasladar sumando y
     * luego no recoger ese día", que la batería ejercita de punta a punta.
     */
    public function testVaciarUnDiaAcumuladoDevuelveLaCestaTrasladadaAPendiente(): void
    {
        self::bootKernel();
        $em = $this->em();

        [$partner, $origin, $target, $vegetables] = $this->chainFixture($em, 'SkipAcc', '2099-11-06', '2099-11-13', 45);

        // La cesta de $origin se trasladó sumando a $target.
        $moved = new PartnerDeliveryShift($partner, $origin, null);
        $moved->setAccumulatedTo($target);
        $em->persist($moved);
        $em->persist(new PartnerBasketExtra($partner, $target, $vegetables, '1.00'));
        $em->flush();

        // "No recoge" en $target: se vacía sin que su contenido viaje a ningún sitio.
        static::getContainer()->get(DeliveryShiftApplier::class)
            ->applySkipIntent($partner, $target, null, 'test:unpark');

        $em->refresh($moved);

        $this->assertNull($moved->getAccumulatedTo(), 'La cesta ya no está colocada en ese día: la marca se suelta.');
        $this->assertTrue($moved->isParked(), 'Vuelve a estar pendiente, recuperable desde la papelera de su semana.');

        $this->cleanUp($em, $partner, $origin, $target);
    }

    /**
     * Encadenar dos traslados sumando (A→B y después B→C) re-apunta la cesta de A hasta C,
     * en vez de devolverla a la papelera.
     *
     * Es el caso que reintroducía el bug original: `AccumulatingMove` lee la composición de
     * B —que YA incluye la cesta de A— y la suma a C. Si el intent de A volviera a
     * "pendiente", su cantidad estaría a la vez viajada a C y ofrecida como recuperable en
     * la papelera de A: recuperarla daría una cesta de más.
     */
    public function testEncadenarDosTrasladosReApuntaLaPrimeraCestaHastaElUltimoDia(): void
    {
        self::bootKernel();
        $em = $this->em();

        [$partner, $a, $b, $vegetables] = $this->chainFixture($em, 'SkipChain', '2099-11-20', '2099-11-27', 47);
        $c = (new Basket())->setDate(new \DateTime('2099-12-04'))->setWeek(49)->setAmount(1);
        $em->persist($c);

        // Primer traslado ya aplicado: la cesta de A está sumada en B.
        $first = new PartnerDeliveryShift($partner, $a, null);
        $first->setAccumulatedTo($b);
        $em->persist($first);
        $em->persist(new PartnerBasketExtra($partner, $b, $vegetables, '1.00'));
        $em->flush();

        // Segundo traslado: B se vacía porque su contenido (patrón de B + la cesta de A) va a C.
        static::getContainer()->get(DeliveryShiftApplier::class)
            ->applySkipIntent($partner, $b, null, 'test:chain', $c);

        $em->refresh($first);

        $this->assertFalse($first->isParked(), 'La cesta de A sigue colocada: no puede volver a la papelera.');
        $this->assertSame($c->getId(), $first->getAccumulatedTo()?->getId(), 'Su cesta viajó hasta C, así que ahí apunta el intent.');

        $this->cleanUp($em, $partner, $a, $b, $c);
    }

    /**
     * Socio y dos semanas sintéticos, más el componente de verdura del catálogo.
     *
     * @return array{0: Partner, 1: Basket, 2: Basket, 3: BasketComponent}
     */
    private function chainFixture(EntityManagerInterface $em, string $prefix, string $firstDate, string $secondDate, int $week): array
    {
        $vegetables = $em->getRepository(BasketComponent::class)->find(BasketComponent::ID_VEGETABLES);
        $this->assertNotNull($vegetables);

        $partner = (new Partner())->setName($prefix . ' ' . uniqid('', true));
        $em->persist($partner);

        $first = (new Basket())->setDate(new \DateTime($firstDate))->setWeek($week)->setAmount(1);
        $second = (new Basket())->setDate(new \DateTime($secondDate))->setWeek($week + 1)->setAmount(1);
        $em->persist($first);
        $em->persist($second);
        $em->flush();

        return [$partner, $first, $second, $vegetables];
    }

    /**
     * Borra lo que el test creó (db_test no tiene rollback por test).
     */
    private function cleanUp(EntityManagerInterface $em, Partner $partner, Basket ...$baskets): void
    {
        foreach ($baskets as $basket) {
            foreach ($em->getRepository(PartnerBasketExtra::class)->findForPartnerAndBasket($partner, $basket) as $extra) {
                $em->remove($extra);
            }
        }
        foreach ($em->getRepository(PartnerDeliveryShift::class)->findBy(['partner' => $partner]) as $shift) {
            $em->remove($shift);
        }
        foreach ($em->getRepository(PartnerEvent::class)->findBy(['partner' => $partner]) as $event) {
            $em->remove($event);
        }
        $em->flush();

        $em->remove($partner);
        foreach ($baskets as $basket) {
            $em->remove($basket);
        }
        $em->flush();
    }
}
