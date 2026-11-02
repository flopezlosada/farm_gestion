<?php

namespace App\Tests\Controller;

use App\Entity\DonationInitiative;
use App\Entity\FeeAdjustment;
use App\Entity\Partner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ajustar la cuota de un socix desde su ficha y las iniciativas a las que va lo que
 * se paga de más: poner, terminar y quitar un ajuste, que una reducción nunca acabe
 * en una iniciativa, y quién puede hacer qué.
 *
 * Todo lo que crea lleva «TEST » delante para limpiarlo sin tocar nada más.
 */
class FeeAdjustmentControllerTest extends AbstractAuthenticatedTest
{
    protected function tearDown(): void
    {
        $em = $this->em();
        foreach ($em->getRepository(FeeAdjustment::class)->findAll() as $adjustment) {
            if (str_starts_with($adjustment->getReason(), 'TEST ')) {
                $em->remove($adjustment);
            }
        }
        $em->flush();
        foreach ($em->getRepository(DonationInitiative::class)->findAll() as $initiative) {
            if (str_starts_with($initiative->getName(), 'TEST ')) {
                $em->remove($initiative);
            }
        }
        $em->flush();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function partner(): Partner
    {
        $partner = $this->em()->getRepository(Partner::class)->findOneBy([], ['id' => 'ASC']);
        $this->assertNotNull($partner, 'db_test necesita al menos un socix de las fixtures.');

        return $partner;
    }

    private function initiative(string $name = 'TEST Cestas RSP', bool $open = true): DonationInitiative
    {
        $initiative = (new DonationInitiative())->setName($name)->setOpen($open);
        $this->em()->persist($initiative);
        $this->em()->flush();

        return $initiative;
    }

    /** «2026-12» para el mes que está $n meses por delante (1 = el que viene). */
    private static function monthAhead(int $n): string
    {
        return (new \DateTimeImmutable('first day of next month'))->modify(sprintf('+%d months', $n - 1))->format('Y-m');
    }

    /** @return list<FeeAdjustment> */
    private function testAdjustments(Partner $partner): array
    {
        $this->em()->clear();

        return array_values(array_filter(
            $this->em()->getRepository(FeeAdjustment::class)->findBy(['partner' => $partner->getId()]),
            static fn (FeeAdjustment $a): bool => str_starts_with($a->getReason(), 'TEST '),
        ));
    }

    public function testPonerUnaAportacionAUnaIniciativa(): void
    {
        $client = $this->createAuthenticatedClient();
        $partner = $this->partner();
        $initiative = $this->initiative();

        $client->request('GET', sprintf('/gestion/partner/%d/fee-adjustments/new?initiative=%d', $partner->getId(), $initiative->getId()));
        $this->assertResponseIsSuccessful();
        $client->submitForm('Guardar ajuste', [
            'fee_adjustment[direction]' => 'more',
            'fee_adjustment[amount]' => '20',
            'fee_adjustment[reason]' => 'TEST aporta a la RSP',
            'fee_adjustment[initiative]' => (string) $initiative->getId(),
            'fee_adjustment[fromMonth]' => self::monthAhead(1),
        ]);

        $this->assertResponseRedirects(sprintf('/gestion/partner/%d#cuota', $partner->getId()));
        $adjustments = $this->testAdjustments($partner);
        $this->assertCount(1, $adjustments);
        $this->assertSame('20.00', $adjustments[0]->getAmount());
        $this->assertSame($initiative->getId(), $adjustments[0]->getInitiative()?->getId());
        $this->assertSame(self::monthAhead(1), $adjustments[0]->getFromMonth()->format('Y-m'));
        $this->assertNull($adjustments[0]->getUntilMonth());

        $client->request('GET', sprintf('/gestion/partner/initiatives/%d', $initiative->getId()));
        $this->assertResponseIsSuccessful();
    }

    /**
     * Si se elige una iniciativa y luego se cambia a «de menos», el campo ya no se ve:
     * se guarda la reducción, sin iniciativa, y no salta un error donde nadie mira.
     */
    public function testUnaReduccionNoVaANingunaIniciativa(): void
    {
        $client = $this->createAuthenticatedClient();
        $partner = $this->partner();
        $initiative = $this->initiative();

        $client->request('GET', sprintf('/gestion/partner/%d/fee-adjustments/new', $partner->getId()));
        $client->submitForm('Guardar ajuste', [
            'fee_adjustment[direction]' => 'less',
            'fee_adjustment[amount]' => '15',
            'fee_adjustment[reason]' => 'TEST no llega a fin de mes',
            'fee_adjustment[initiative]' => (string) $initiative->getId(),
            'fee_adjustment[fromMonth]' => self::monthAhead(2),
            'fee_adjustment[untilMonth]' => self::monthAhead(4),
        ]);

        $this->assertResponseRedirects();
        $adjustments = $this->testAdjustments($partner);
        $this->assertCount(1, $adjustments);
        $this->assertSame('-15.00', $adjustments[0]->getAmount());
        $this->assertNull($adjustments[0]->getInitiative());
        $this->assertSame(self::monthAhead(4), $adjustments[0]->getUntilMonth()->format('Y-m'));
    }

    public function testNoTerminaAntesDeEmpezar(): void
    {
        $client = $this->createAuthenticatedClient();
        $partner = $this->partner();

        $client->request('GET', sprintf('/gestion/partner/%d/fee-adjustments/new', $partner->getId()));
        $client->submitForm('Guardar ajuste', [
            'fee_adjustment[direction]' => 'more',
            'fee_adjustment[amount]' => '10',
            'fee_adjustment[reason]' => 'TEST al revés',
            'fee_adjustment[fromMonth]' => self::monthAhead(5),
            'fee_adjustment[untilMonth]' => self::monthAhead(3),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('main', 'No puede terminar antes de empezar.');
        $this->assertSame([], $this->testAdjustments($partner));
    }

    /**
     * Rellena el formulario de un ajuste nuevo saltándose lo que el navegador no
     * dejaría elegir (un mes fuera de la lista, céntimos) y lo envía: así se prueba
     * lo que dice el servidor.
     *
     * @param array<string, string> $values
     */
    private function submitRaw(array $values): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', sprintf('/gestion/partner/%d/fee-adjustments/new', $this->partner()->getId()));
        $form = $crawler->selectButton('Guardar ajuste')->form();
        $form->disableValidation();
        $form->setValues($values + [
            'fee_adjustment[direction]' => 'more',
            'fee_adjustment[amount]' => '10',
            'fee_adjustment[reason]' => 'TEST fuera de lo permitido',
            'fee_adjustment[fromMonth]' => self::monthAhead(1),
        ]);
        $client->submit($form);
    }

    /** Este mes ya puede ir en una remesa: un ajuste nuevo empieza como pronto el que viene. */
    public function testNoEmpiezaEsteMes(): void
    {
        $this->submitRaw(['fee_adjustment[fromMonth]' => (new \DateTimeImmutable())->format('Y-m')]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('main', 'Como pronto, desde el mes que viene.');
        $this->assertSame([], $this->testAdjustments($this->partner()));
    }

    public function testNoAdmiteCentimos(): void
    {
        $this->submitRaw(['fee_adjustment[amount]' => '12.50']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('main', 'Escribe euros enteros, sin céntimos.');
        $this->assertSame([], $this->testAdjustments($this->partner()));
    }

    /** Los meses son una lista que empieza en el que viene; el importe, euros enteros. */
    public function testElFormularioOfreceMesesDesdeElQueVieneYEurosEnteros(): void
    {
        $client = $this->createAuthenticatedClient();
        $crawler = $client->request('GET', sprintf('/gestion/partner/%d/fee-adjustments/new', $this->partner()->getId()));

        $from = $crawler->filter('select[name="fee_adjustment[fromMonth]"] option');
        $this->assertSame(self::monthAhead(1), $from->first()->attr('value'));
        $this->assertSame(self::monthAhead(1), $crawler->filter('select[name="fee_adjustment[fromMonth]"] option[selected]')->attr('value'), 'Por defecto, el que viene.');
        $until = $crawler->filter('select[name="fee_adjustment[untilMonth]"] option');
        $this->assertSame(['', self::monthAhead(1)], [$until->eq(0)->attr('value'), $until->eq(1)->attr('value')], 'Primero «sin fin».');
        $amount = $crawler->filter('input[name="fee_adjustment[amount]"]');
        $this->assertSame(['1', '1'], [$amount->attr('step'), $amount->attr('min')]);
    }

    public function testTerminarYQuitar(): void
    {
        $client = $this->createAuthenticatedClient();
        $partner = $this->partner();
        $adjustment = new FeeAdjustment($partner, '12', 'TEST amplía su cuota', new \DateTimeImmutable('first day of this month'));
        $this->em()->persist($adjustment);
        $this->em()->flush();
        $id = $adjustment->getId();
        $thisMonth = (new \DateTimeImmutable('today'))->format('Y-m');

        $crawler = $client->request('GET', sprintf('/gestion/partner/%d', $partner->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#cuota', 'TEST amplía su cuota');
        $client->submit($crawler->filter(sprintf('form[action="/gestion/partner/fee-adjustments/%d/end"]', $id))->form());
        $this->assertResponseRedirects();
        $this->assertSame($thisMonth, $this->em()->find(FeeAdjustment::class, $id)->getUntilMonth()->format('Y-m'));

        $crawler = $client->request('GET', sprintf('/gestion/partner/%d', $partner->getId()));
        $client->submit($crawler->filter(sprintf('form[action="/gestion/partner/fee-adjustments/%d/delete"]', $id))->form());
        $this->assertResponseRedirects();
        $this->em()->clear();
        $this->assertNull($this->em()->find(FeeAdjustment::class, $id));
    }

    /** Lo de más va al convenio: si sólo hay una iniciativa abierta, el formulario ya la trae elegida. */
    public function testLoDeMasVaPorDefectoALaUnicaIniciativaAbierta(): void
    {
        $client = $this->createAuthenticatedClient();
        $partner = $this->partner();
        $initiative = $this->initiative();
        $this->assertCount(1, $this->em()->getRepository(DonationInitiative::class)->findBy(['open' => true]), 'db_test no debería traer iniciativas abiertas de serie.');

        $crawler = $client->request('GET', sprintf('/gestion/partner/%d/fee-adjustments/new', $partner->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSame((string) $initiative->getId(), $crawler->filter('select[name="fee_adjustment[initiative]"] option[selected]')->attr('value'));
    }

    /** Con varias abiertas no se adivina: se elige a mano. */
    public function testConVariasIniciativasAbiertasNoSeEligeNinguna(): void
    {
        $client = $this->createAuthenticatedClient();
        $partner = $this->partner();
        $this->initiative('TEST Cestas RSP');
        $this->initiative('TEST Huerta escolar');

        $crawler = $client->request('GET', sprintf('/gestion/partner/%d/fee-adjustments/new', $partner->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('select[name="fee_adjustment[initiative]"] option[selected]'));
    }

    /** Lo de más que no va a ninguna iniciativa lo dice: es para la asociación. Una reducción no va a ningún sitio. */
    public function testLaFichaDiceADondeVaLoDeMas(): void
    {
        $client = $this->createAuthenticatedClient();
        $partner = $this->partner();
        $thisMonth = new \DateTimeImmutable('first day of this month');
        $this->em()->persist(new FeeAdjustment($partner, '12', 'TEST amplía su cuota', $thisMonth));
        $this->em()->persist(new FeeAdjustment($partner, '-5', 'TEST no llega', $thisMonth));
        $this->em()->flush();

        $crawler = $client->request('GET', sprintf('/gestion/partner/%d', $partner->getId()));

        $this->assertResponseIsSuccessful();
        $rows = $crawler->filter('#cuota .fee-adj');
        $more = $rows->reduce(static fn ($r): bool => str_contains($r->text(), 'TEST amplía su cuota'));
        $less = $rows->reduce(static fn ($r): bool => str_contains($r->text(), 'TEST no llega'));
        $this->assertStringContainsString('para la asociación', $more->text());
        $this->assertStringNotContainsString('para la asociación', $less->text());
    }

    public function testCrearUnaIniciativa(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/partner/initiatives/new');
        $client->submitForm('Guardar', [
            'donation_initiative[name]' => 'TEST Huerta escolar',
            'donation_initiative[description]' => 'Cestas para el comedor.',
        ]);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('main', 'TEST Huerta escolar');
        $client->request('GET', '/gestion/partner/initiatives');
        $this->assertSelectorTextContains('main', 'TEST Huerta escolar');
    }

    /** Quien sólo lee socixs ve el bloque y las iniciativas, pero no cambia nada. */
    public function testQuienSoloLeeNoAjustaCuotas(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_SOCIXS']));
        $partner = $this->partner();

        $client->request('GET', sprintf('/gestion/partner/%d', $partner->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#cuota');
        $this->assertSelectorNotExists('#cuota a[href$="/fee-adjustments/new"]');

        $client->request('GET', '/gestion/partner/initiatives');
        $this->assertResponseIsSuccessful();
        $client->request('GET', sprintf('/gestion/partner/%d/fee-adjustments/new', $partner->getId()));
        $this->assertResponseStatusCodeSame(403);
        $client->request('GET', '/gestion/partner/initiatives/new');
        $this->assertResponseStatusCodeSame(403);
    }
}
