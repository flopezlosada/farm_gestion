<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Tests del registro de cambios de reparto (/gestion/reparto/historial-cambios).
 * El alta de cambios ya NO vive aquí (es el calendario de recogida de la
 * ficha del socix); esta pantalla es de consulta, así que se prueba lo que
 * es suyo: listar los cambios de una semana, cancelar un cambio de día y
 * deshacer un "no recoge".
 */
class DeliveryShiftsAdminActionsTest extends AbstractAuthenticatedTest
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get('doctrine.orm.entity_manager');
    }

    public function testRegistroListaElCambioDeLaSemanaYPermiteCancelar(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em($client);

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNotNull($partner);

        [$from, $to] = $this->resolveFromTo($em);

        // El cambio se crea con el servicio (el alta ya no es de esta pantalla).
        $client->getContainer()->get(DeliveryShiftApplier::class)
            ->apply($partner, $from, $to, actor: 'gestor:test');
        $em->flush();
        $em->clear();

        $shift = $em->getRepository(PartnerDeliveryShift::class)->findOneBy(['partner' => $partner]);
        $this->assertNotNull($shift, 'El cambio debe existir tras aplicarlo con el servicio');

        // El registro de la semana ORIGEN lo lista y ofrece el "Deshacer".
        $crawler = $client->request('GET', '/gestion/reparto/historial-cambios?basket=' . $from->getId());
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $dialogId = 'confirm-shift-cancel-' . $shift->getId();
        $cancelToken = (string) $crawler
            ->filter(sprintf('#%s input[name="_csrf_token"]', $dialogId))
            ->first()->attr('value');
        $this->assertNotEmpty($cancelToken, 'La semana futura debe ofrecer el botón Deshacer');

        $client->request('POST', '/gestion/reparto/historial-cambios/' . $shift->getId() . '/cancelar', [
            '_csrf_token' => $cancelToken,
        ]);
        $client->followRedirect();

        $em->clear();
        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        $this->assertNull(
            $em->getRepository(PartnerDeliveryShift::class)->findOneBy(['partner' => $partner]),
            'El cambio debe quedar borrado tras cancelar'
        );
    }

    public function testDeshacerNoRecogeDeUnaSemanaConcreta(): void
    {
        $client = $this->createAuthenticatedClient();
        $em = $this->em($client);

        $partner = $em->getRepository(Partner::class)
            ->findOneBy(['email' => PartnerUserFixtures::USER_SOCIX_EMAIL]);
        [$from] = $this->resolveFromTo($em);

        // Materializamos la semana y marcamos al socix como "no recoge" (status 2)
        // directamente, para tener un salto que deshacer.
        $client->getContainer()->get(WeeklyBasketGenerator::class)->generateForBasket($from);
        $em->flush();

        $wb = $em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $partner, 'basket' => $from]);
        if ($wb === null) {
            $this->markTestSkipped('Las fixtures no materializan cesta del socix esa semana.');
        }
        $wb->setWeeklyBasketStatus($em->getRepository(WeeklyBasketStatus::class)->find(2));
        $em->flush();
        $em->clear();

        // El registro lo muestra en "No recogen" con el botón Deshacer.
        $crawler = $client->request('GET', '/gestion/reparto/historial-cambios?basket=' . $from->getId());
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $dialogId = 'confirm-skip-undo-' . $partner->getId() . '-' . $from->getId();
        $token = (string) $crawler
            ->filter(sprintf('#%s input[name="_csrf_token"]', $dialogId))
            ->first()->attr('value');
        $this->assertNotEmpty($token);

        $client->request('POST', '/gestion/reparto/historial-cambios/skip', [
            '_csrf_token' => $token,
            'partner_id' => $partner->getId(),
            'basket_id' => $from->getId(),
        ]);
        $client->followRedirect();

        $em->clear();
        $wb = $em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $partner, 'basket' => $from]);
        $this->assertSame(1, $wb->getWeeklyBasketStatus()?->getId(), 'Tras deshacer, vuelve a recoger (status 1)');
    }

    /**
     * Encuentra los dos primeros Baskets futuros (asumimos que las fixtures
     * crean ambos como un par consecutivo para el socix).
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
