<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests de las acciones POST del admin sobre cambios puntuales de
 * viernes (/gestion/reparto/cambios-viernes). Cubren aplicar y
 * cancelar usando los mismos servicios que el panel del socix,
 * pero con el flag de "forzar reglas bypassables" disponible.
 */
class DeliveryShiftsAdminActionsTest extends AbstractAuthenticatedTest
{
    private function em(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get('doctrine.orm.entity_manager');
    }

    public function testAdminAplicaShiftYRegistraEvento(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em($client);

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNotNull($partner);

        [$from, $to] = $this->resolveFromTo($em);

        $crawler = $client->request('GET', '/gestion/reparto/cambios-viernes');
        $token = (string) $crawler->filter('form[action$="/cambios-viernes/aplicar"] input[name="_csrf_token"]')->first()->attr('value');

        $client->request('POST', '/gestion/reparto/cambios-viernes/aplicar', [
            '_csrf_token' => $token,
            'partner_id' => $partner->getId(),
            'from_basket_id' => $from->getId(),
            'to_basket_id' => $to->getId(),
        ]);
        $client->followRedirect();

        $em->clear();
        $shift = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partner]);
        $this->assertNotNull($shift, 'Admin debe poder crear el shift');

        $event = $em->getRepository(PartnerEvent::class)->createQueryBuilder('e')
            ->where('e.partner = :p AND e.type = :t')
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('p', $partner)
            ->setParameter('t', PartnerEvent::TYPE_WEEK_SWAP)
            ->getQuery()->getOneOrNullResult();
        $this->assertNotNull($event);
        $this->assertStringStartsWith('gestor:', (string) $event->getActor(), 'Actor debe identificar al gestor');
    }

    public function testAdminCancelaShiftRevierte(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em($client);

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        [$from, $to] = $this->resolveFromTo($em);

        // Aplicamos uno primero.
        $crawler = $client->request('GET', '/gestion/reparto/cambios-viernes');
        $token = (string) $crawler->filter('form[action$="/cambios-viernes/aplicar"] input[name="_csrf_token"]')->first()->attr('value');
        $client->request('POST', '/gestion/reparto/cambios-viernes/aplicar', [
            '_csrf_token' => $token,
            'partner_id' => $partner->getId(),
            'from_basket_id' => $from->getId(),
            'to_basket_id' => $to->getId(),
        ]);
        $client->followRedirect();

        $em->clear();
        $shift = $em->getRepository(PartnerDeliveryShift::class)->findOneBy(['partner' => $partner]);
        $this->assertNotNull($shift);

        // Cancelamos.
        $crawler = $client->request('GET', '/gestion/reparto/cambios-viernes');
        $cancelToken = (string) $crawler->filter('form[action$="/cambios-viernes/' . $shift->getId() . '/cancelar"] input[name="_csrf_token"]')->first()->attr('value');
        $client->request('POST', '/gestion/reparto/cambios-viernes/' . $shift->getId() . '/cancelar', [
            '_csrf_token' => $cancelToken,
        ]);
        $client->followRedirect();

        $em->clear();
        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNull(
            $em->getRepository(PartnerDeliveryShift::class)->findOneBy(['partner' => $partner]),
            'El shift debe quedar borrado tras cancelar'
        );
    }

    /**
     * Encuentra los dos primeros Baskets futuros (asumimos que las
     * fixtures crean ambos como un par consecutivo para el socix).
     *
     * @return array{0:Basket,1:Basket}
     */
    private function resolveFromTo(EntityManagerInterface $em): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $baskets = $em->getRepository(Basket::class)->createQueryBuilder('b')
            ->where('b.date >= :today')
            ->setParameter('today', $today)
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(2)
            ->getQuery()->getResult();

        $this->assertCount(2, $baskets, 'Necesitamos al menos 2 Baskets futuros en fixtures');
        return [$baskets[0], $baskets[1]];
    }
}
