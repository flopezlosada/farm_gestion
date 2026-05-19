<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests de las acciones POST del panel del socix sobre su próxima cesta:
 * saltar/recuperar, cambiar de nodo, pedir y cancelar cambio puntual de
 * viernes. Verifican que cada acción persiste el estado correcto
 * (WeeklyBasket, PartnerDeliveryShift) y deja el PartnerEvent esperado
 * en el feed.
 */
class PanelBasketActionsTest extends AbstractPartnerAuthenticatedTest
{
    /**
     * Resetea el estado mutable del socix (WB status, shifts, events) para
     * que cada test arranque con la misma foto que dejan las fixtures —
     * sin volver a recargarlas, que sería lento.
     */
    private function em(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * Resetea el estado mutable del socix (WB status, shifts, events) para
     * que cada test arranque con la misma foto que dejan las fixtures —
     * sin recargarlas, que sería lento. Se invoca al inicio de cada test
     * con el cliente ya creado: no podemos bootear un kernel auxiliar
     * porque WebTestCase::createClient() exige que sea el primer boot.
     */
    private function resetSocixState(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $conn = $this->em($client)->getConnection();
        $params = ['email' => PartnerUserFixtures::USER_SOCIX_EMAIL];

        $conn->executeStatement(
            'UPDATE weekly_basket wb
                INNER JOIN partner p ON p.id = wb.partner_id
                SET wb.weekly_basket_status_id = 1
                WHERE p.email = :email',
            $params,
        );
        $conn->executeStatement(
            'DELETE pds FROM partner_delivery_shift pds
                INNER JOIN partner p ON p.id = pds.partner_id
                WHERE p.email = :email',
            $params,
        );
        $conn->executeStatement(
            'DELETE pe FROM partner_event pe
                INNER JOIN partner p ON p.id = pe.partner_id
                WHERE p.email = :email',
            $params,
        );
    }

    private function reloadSocixPartner(EntityManagerInterface $em): Partner
    {
        return $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
    }

    public function testSkipToggleCambiaStatusDeWBYEmiteEvent(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        $wb = $em->getRepository(WeeklyBasket::class)
            ->findOneBy(['partner' => $partner], ['id' => 'DESC']);
        $this->assertNotNull($wb);
        $this->assertSame(1, $wb->getWeeklyBasketStatus()->getId(), 'Estado inicial debe ser Recoge');

        $crawler = $client->request('GET', '/panel/cesta');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');

        $client->request('POST', '/panel/cesta/skip-toggle', ['_csrf_token' => $token]);
        $client->followRedirect();

        $em->clear();
        $wb = $em->getRepository(WeeklyBasket::class)->find($wb->getId());
        $this->assertSame(2, $wb->getWeeklyBasketStatus()->getId(), 'Tras skip toggle, status debe ser 2');

        $event = $em->getRepository(PartnerEvent::class)
            ->findOneBy(['partner' => $partner, 'type' => PartnerEvent::TYPE_BASKET_SKIP], ['id' => 'DESC']);
        $this->assertNotNull($event, 'Debe quedar PartnerEvent BASKET_SKIP en el feed');
    }

    public function testShiftRequestCreaEntidadYAplicaCascada(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        // Encontramos el `from` (próxima WB) y el `to` (siguiente Basket).
        $nextWb = $em->getRepository(WeeklyBasket::class)
            ->findOneBy(['partner' => $partner], ['id' => 'DESC']);
        $from = $nextWb->getBasket();

        $to = $em->getRepository(Basket::class)->createQueryBuilder('b')
            ->where('b.date > :d')
            ->setParameter('d', $from->getDate())
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        $this->assertNotNull($to, 'Necesitamos un Basket siguiente al actual en fixtures');

        $crawler = $client->request('GET', '/panel/cesta');
        $token = (string) $crawler->filter('form[action$="cambiar-viernes"] input[name="_csrf_token"]')->first()->attr('value');

        $client->request('POST', '/panel/cesta/cambiar-viernes', [
            '_csrf_token' => $token,
            'to_basket_id' => $to->getId(),
        ]);
        $client->followRedirect();

        $em->clear();
        $shift = $em->getRepository(PartnerDeliveryShift::class)
            ->findOneBy(['partner' => $partner]);
        $this->assertNotNull($shift, 'Debe haberse creado un PartnerDeliveryShift');

        $partner = $this->reloadSocixPartner($em);
        $wbFrom = $em->getRepository(WeeklyBasket::class)
            ->findOneBy(['partner' => $partner, 'basket' => $shift->getFromBasket()]);
        $this->assertSame(2, $wbFrom->getWeeklyBasketStatus()->getId(), 'WB(from) debe quedar en status 2');

        $event = $em->getRepository(PartnerEvent::class)
            ->findOneBy(['partner' => $partner, 'type' => PartnerEvent::TYPE_WEEK_SWAP], ['id' => 'DESC']);
        $this->assertNotNull($event);
        $this->assertFalse($event->getPayload()['cancelled'] ?? true);
    }

    public function testShiftCancelRevierteCascada(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->resetSocixState($client);
        $em = $this->em($client);
        $partner = $this->reloadSocixPartner($em);

        // Replicamos el setup del test anterior: creamos un shift.
        $nextWb = $em->getRepository(WeeklyBasket::class)
            ->findOneBy(['partner' => $partner], ['id' => 'DESC']);
        $from = $nextWb->getBasket();
        $to = $em->getRepository(Basket::class)->createQueryBuilder('b')
            ->where('b.date > :d')
            ->setParameter('d', $from->getDate())
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        $crawler = $client->request('GET', '/panel/cesta');
        $token = (string) $crawler->filter('form[action$="cambiar-viernes"] input[name="_csrf_token"]')->first()->attr('value');
        $client->request('POST', '/panel/cesta/cambiar-viernes', [
            '_csrf_token' => $token,
            'to_basket_id' => $to->getId(),
        ]);
        $client->followRedirect();

        // Cancelamos.
        $crawler = $client->request('GET', '/panel/cesta');
        $token = (string) $crawler->filter('form[action$="cancelar-cambio-viernes"] input[name="_csrf_token"]')->first()->attr('value');
        $client->request('POST', '/panel/cesta/cancelar-cambio-viernes', ['_csrf_token' => $token]);
        $client->followRedirect();

        $em->clear();
        $partner = $this->reloadSocixPartner($em);
        $shift = $em->getRepository(PartnerDeliveryShift::class)->findOneBy(['partner' => $partner]);
        $this->assertNull($shift, 'El shift debe borrarse al cancelar');

        $wbFrom = $em->getRepository(WeeklyBasket::class)
            ->findOneBy(['partner' => $partner, 'basket' => $from]);
        $this->assertSame(1, $wbFrom->getWeeklyBasketStatus()->getId(), 'WB(from) debe volver a status 1');

        $cancelEvent = $em->getRepository(PartnerEvent::class)->createQueryBuilder('e')
            ->where('e.partner = :p AND e.type = :t')
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('p', $partner)
            ->setParameter('t', PartnerEvent::TYPE_WEEK_SWAP)
            ->getQuery()->getOneOrNullResult();
        $this->assertNotNull($cancelEvent);
        $this->assertTrue($cancelEvent->getPayload()['cancelled'] ?? false, 'Último WEEK_SWAP debe tener cancelled=true');
    }
}
