<?php

namespace App\Tests\Repository;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Blinda la unicidad de los intents de ENTREGA ENTERA (component_id NULL) a nivel de BBDD.
 *
 * Regresión del 500 de producción (socio 107, 2026-07-23): una CARRERA de doble submit dejó
 * dos PartnerDeliveryShift de entrega entera con el mismo from_basket, y findOutgoing —que
 * espera 0/1— reventaba con NonUniqueResultException. Las guardas de código del applier no
 * cierran una carrera; sí lo hace la columna generada `component_key` = COALESCE(component_id, 0)
 * bajo los índices únicos. Este test verifica que la BBDD rechaza el duplicado y que los intents
 * POR COMPONENTE (distinto component_id) siguen conviviendo.
 */
class PartnerDeliveryShiftRepositoryTest extends KernelTestCase
{
    private const ID_VEGETABLES = 1;
    private const ID_EGGS = 2;

    private EntityManagerInterface $em;
    private Partner $partner;
    private Basket $from;
    private Basket $to;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        $this->partner = (new Partner())->setName('PDShiftTest ' . uniqid('', true));
        $this->em->persist($this->partner);

        $this->from = $this->makeBasket('2099-07-03', 27);
        $this->to = $this->makeBasket('2099-07-10', 28);
        $this->em->flush();
    }

    public function testRechazaDosIntentsDeEntregaEnteraDelMismoOrigen(): void
    {
        // 1º intent: mover toda la entrega de `from` a `to`.
        $this->em->persist(new PartnerDeliveryShift($this->partner, $this->from, $this->to));
        $this->em->flush();

        // 2º intent de entrega entera desde el MISMO `from` (aquí un "no recoge"): la carrera que
        // se coló en prod. component_key = 0 en ambos → viola uniq_partner_from_basket.
        $this->em->persist(new PartnerDeliveryShift($this->partner, $this->from, null));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testRechazaDosIntentsDeEntregaEnteraEntrandoAlMismoDestino(): void
    {
        $other = $this->makeBasket('2099-07-17', 29);
        $this->em->flush();

        // Dos entregas enteras entrando al mismo `to` (from distintos): rompía findIncoming.
        $this->em->persist(new PartnerDeliveryShift($this->partner, $this->from, $this->to));
        $this->em->flush();
        $this->em->persist(new PartnerDeliveryShift($this->partner, $other, $this->to));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testPermiteIntentsDeDistintoComponenteDesdeElMismoOrigen(): void
    {
        $veg = $this->em->getRepository(BasketComponent::class)->find(self::ID_VEGETABLES);
        $eggs = $this->em->getRepository(BasketComponent::class)->find(self::ID_EGGS);
        $this->assertNotNull($veg);
        $this->assertNotNull($eggs);

        // Entrega entera (key 0) + verdura (key 1) + huevos (key 2) desde el mismo `from`:
        // claves distintas → la constraint NO debe bloquearlos (caso SANTOS).
        $this->em->persist(new PartnerDeliveryShift($this->partner, $this->from, null));
        $this->em->persist(new PartnerDeliveryShift($this->partner, $this->from, $this->to, $veg));
        $this->em->persist(new PartnerDeliveryShift($this->partner, $this->from, $this->to, $eggs));
        $this->em->flush();

        $count = (int) $this->em->createQuery(
            'SELECT COUNT(s.id) FROM App\Entity\PartnerDeliveryShift s WHERE s.partner = :p AND s.fromBasket = :f'
        )->setParameter('p', $this->partner)->setParameter('f', $this->from)->getSingleScalarResult();

        $this->assertSame(3, $count, 'Los tres intents (entera + verdura + huevos) conviven.');
    }

    private function makeBasket(string $date, int $week): Basket
    {
        $basket = (new Basket())->setDate(new \DateTime($date))->setWeek($week)->setAmount(1);
        $this->em->persist($basket);

        return $basket;
    }
}
