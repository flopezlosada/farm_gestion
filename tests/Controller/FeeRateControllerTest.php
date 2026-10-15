<?php

namespace App\Tests\Controller;

use App\Entity\FeeConcept;
use App\Entity\FeeRate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La pantalla de tarifas: poner un precio desde una fecha, que no se repita el mismo
 * día, quitar uno puesto por error, y quién puede hacer qué. Las fechas van en 2098
 * para no cruzarse con precios reales de db_test.
 */
class FeeRateControllerTest extends AbstractAuthenticatedTest
{
    protected function tearDown(): void
    {
        $em = $this->em();
        foreach ($em->getRepository(FeeRate::class)->findAll() as $rate) {
            if ($rate->getValidFrom()->format('Y') === '2098') {
                $em->remove($rate);
            }
        }
        $em->flush();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @return list<FeeRate> */
    private function ratesIn2098(): array
    {
        return array_values(array_filter(
            $this->em()->getRepository(FeeRate::class)->findBy(['concept' => FeeConcept::VegBiweekly]),
            static fn (FeeRate $r): bool => $r->getValidFrom()->format('Y') === '2098',
        ));
    }

    public function testListadoConLosSeisConceptos(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', '/gestion/partner/fee-rates');

        $this->assertResponseIsSuccessful();
        foreach (FeeConcept::cases() as $concept) {
            $this->assertSelectorTextContains('main', $concept->label());
        }
        $this->assertCount(1, $crawler->filter('a[href="/gestion/partner/fee-rates/new"]'), 'Quien edita ve «Nuevo precio».');
    }

    /** Un precio programado sale como próximo cambio; el mismo concepto y día no se repite. */
    public function testPonerUnPrecioYNoRepetirloElMismoDia(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/fee-rates/new?concept=veg_biweekly');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Guardar precio', [
            'fee_rate[concept]' => 'veg_biweekly',
            'fee_rate[price]' => '61.50',
            'fee_rate[validFrom]' => '2098-01-01',
        ]);
        $this->assertResponseRedirects('/gestion/partner/fee-rates');
        $client->followRedirect();
        $this->assertSelectorTextContains('main', '61,50 € desde 01/01/2098');

        $rates = $this->ratesIn2098();
        $this->assertCount(1, $rates);
        $this->assertSame('61.50', $rates[0]->getPrice());
        $this->assertNotNull($rates[0]->getCreatedBy(), 'Se guarda quién lo puso.');

        $client->request('GET', '/gestion/partner/fee-rates/new');
        $client->submitForm('Guardar precio', [
            'fee_rate[concept]' => 'veg_biweekly',
            'fee_rate[price]' => '70',
            'fee_rate[validFrom]' => '2098-01-01',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'ya tiene un precio desde el 01/01/2098');
        $this->em()->clear();
        $this->assertCount(1, $this->ratesIn2098(), 'Dos precios el mismo día no dicen cuál vale.');
    }

    public function testUnPrecioACeroNoSeGuarda(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/fee-rates/new');
        $client->submitForm('Guardar precio', [
            'fee_rate[concept]' => 'veg_biweekly',
            'fee_rate[price]' => '0',
            'fee_rate[validFrom]' => '2098-02-01',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame([], $this->ratesIn2098());
    }

    public function testQuitarUnPrecioPuestoPorError(): void
    {
        $client = $this->createAuthenticatedClient();
        $rate = new FeeRate(FeeConcept::VegBiweekly, '62.00', new \DateTimeImmutable('2098-03-01'), null);
        $this->em()->persist($rate);
        $this->em()->flush();
        $id = $rate->getId();

        $crawler = $client->request('GET', '/gestion/partner/fee-rates');
        $form = $crawler->filter(sprintf('form[action="/gestion/partner/fee-rates/%d/delete"]', $id))->form();
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/partner/fee-rates');
        $this->em()->clear();
        $this->assertNull($this->em()->find(FeeRate::class, $id));
    }

    /** Quien sólo lee socixs ve las tarifas, pero no puede poner ni quitar precios. */
    public function testQuienSoloLeeNoCambiaPrecios(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_SOCIXS']));
        $rate = new FeeRate(FeeConcept::VegBiweekly, '63.00', new \DateTimeImmutable('2098-04-01'), null);
        $this->em()->persist($rate);
        $this->em()->flush();

        $crawler = $client->request('GET', '/gestion/partner/fee-rates');
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('a[href="/gestion/partner/fee-rates/new"]'));
        $this->assertCount(0, $crawler->filter('form[action$="/delete"]'));

        $client->request('GET', '/gestion/partner/fee-rates/new');
        $this->assertResponseStatusCodeSame(403);
        $client->request('POST', sprintf('/gestion/partner/fee-rates/%d/delete', $rate->getId()));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSinPermisoDeSocixsNoEntra(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_GRANJA']));
        $client->request('GET', '/gestion/partner/fee-rates');

        $this->assertResponseStatusCodeSame(403);
    }
}
