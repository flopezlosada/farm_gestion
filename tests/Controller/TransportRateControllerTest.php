<?php

namespace App\Tests\Controller;

use App\Entity\TransportRate;
use App\Entity\WeeklyBasketGroup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El transporte en la pantalla de tarifas: poner el precio de un grupo desde una
 * fecha, que no se repita el mismo día, quitar uno puesto por error, y que quien sólo
 * lee no pueda. Cada prueba usa un grupo propio que se borra al terminar (con él, sus
 * precios).
 */
class TransportRateControllerTest extends AbstractAuthenticatedTest
{
    private ?int $groupId = null;

    protected function tearDown(): void
    {
        if ($this->groupId !== null) {
            $em = $this->em();
            $em->clear();
            $group = $em->find(WeeklyBasketGroup::class, $this->groupId);
            if ($group !== null) {
                array_map($em->remove(...), $em->getRepository(TransportRate::class)->findBy(['pickupGroup' => $group]));
                $em->remove($group);
                $em->flush();
            }
        }
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** Un grupo de recogida lejos de la finca, sólo para esta prueba. */
    private function group(): WeeklyBasketGroup
    {
        $group = (new WeeklyBasketGroup())->setName('Grupo lejano de prueba')->setColor('#336699');
        $this->em()->persist($group);
        $this->em()->flush();
        $this->groupId = $group->getId();

        return $group;
    }

    /** @return list<TransportRate> */
    private function rates(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(TransportRate::class)->findBy(['pickupGroup' => $this->groupId]);
    }

    /** Se escribe como se escribe aquí (coma decimal) y sale en la tabla; el mismo día no se repite. */
    public function testPonerUnTransporteYNoRepetirloElMismoDia(): void
    {
        $client = $this->createAuthenticatedClient();
        $group = $this->group();

        $client->request('GET', '/gestion/partner/fee-rates/transport/new?group=' . $group->getId());
        $this->assertResponseIsSuccessful();
        $client->submitForm('Guardar transporte', [
            'transport_rate[pickupGroup]' => (string) $group->getId(),
            'transport_rate[pricePerDelivery]' => '4,50',
            'transport_rate[validFrom]' => '2098-01-01',
        ]);
        $this->assertResponseRedirects('/gestion/partner/fee-rates');
        $client->followRedirect();
        $this->assertSelectorTextContains('main', 'Transporte a Grupo lejano de prueba: 4,50 € por cesta desde el 01/01/2098.');
        $this->assertSelectorTextContains('main', 'Grupo lejano de prueba');

        $rates = $this->rates();
        $this->assertCount(1, $rates);
        $this->assertSame('4.50', $rates[0]->getPricePerDelivery());
        $this->assertNotNull($rates[0]->getCreatedBy(), 'Se guarda quién lo puso.');

        $client->request('GET', '/gestion/partner/fee-rates/transport/new');
        $client->submitForm('Guardar transporte', [
            'transport_rate[pickupGroup]' => (string) $group->getId(),
            'transport_rate[pricePerDelivery]' => '6',
            'transport_rate[validFrom]' => '2098-01-01',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'ya tiene un precio de transporte desde el 01/01/2098');
        $this->assertCount(1, $this->rates(), 'Dos precios el mismo día no dicen cuál vale.');
    }

    public function testUnTransporteACeroNoSeGuarda(): void
    {
        $client = $this->createAuthenticatedClient();
        $group = $this->group();
        $client->request('GET', '/gestion/partner/fee-rates/transport/new');
        $client->submitForm('Guardar transporte', [
            'transport_rate[pickupGroup]' => (string) $group->getId(),
            'transport_rate[pricePerDelivery]' => '0',
            'transport_rate[validFrom]' => '2098-02-01',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame([], $this->rates());
    }

    public function testQuitarUnTransportePuestoPorError(): void
    {
        $client = $this->createAuthenticatedClient();
        $rate = new TransportRate($this->group(), '5.00', new \DateTimeImmutable('2098-03-01'), null);
        $this->em()->persist($rate);
        $this->em()->flush();

        $crawler = $client->request('GET', '/gestion/partner/fee-rates');
        $client->submit($crawler->filter(sprintf('form[action="/gestion/partner/fee-rates/transport/%d/delete"]', $rate->getId()))->form());

        $this->assertResponseRedirects('/gestion/partner/fee-rates');
        $this->assertSame([], $this->rates());
    }

    /** Quien sólo lee socixs ve el transporte, pero no puede poner ni quitar precios. */
    public function testQuienSoloLeeNoCambiaElTransporte(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_SOCIXS']));
        $rate = new TransportRate($this->group(), '5.00', new \DateTimeImmutable('2098-04-01'), null);
        $this->em()->persist($rate);
        $this->em()->flush();

        $crawler = $client->request('GET', '/gestion/partner/fee-rates');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'Grupo lejano de prueba');
        $this->assertCount(0, $crawler->filter('a[href^="/gestion/partner/fee-rates/transport/new"]'));

        $client->request('GET', '/gestion/partner/fee-rates/transport/new');
        $this->assertResponseStatusCodeSame(403);
        $client->request('POST', sprintf('/gestion/partner/fee-rates/transport/%d/delete', $rate->getId()));
        $this->assertResponseStatusCodeSame(403);
    }
}
