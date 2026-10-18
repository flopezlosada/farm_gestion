<?php

namespace App\Tests\Controller;

use App\Entity\AccountantDelivery;
use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\ReceivedInvoice;
use App\Entity\Setting;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Un trimestre de punta a punta, por las pantallas: el pago que trajo el banco sale
 * en «Para la gestoría» como gasto sin factura; al subir la factura y confirmarla se
 * engancha a ese pago y pasa al paquete; entregado el trimestre, el apunte ya no se
 * cambia, y quien lo intenta con la página abierta de antes sólo ve por qué no.
 *
 * Cada módulo tiene su test; éste cuida las costuras entre ellos. Autocontenido: el
 * 3T2097, con el reloj ya en el 4T2097.
 */
class AccountingQuarterFlowTest extends AbstractAuthenticatedTest
{
    use ClockSensitiveTrait;

    private const FILE = 'factura-trimestre.pdf';
    private const GESTORIA = '/gestion/accounting/invoices/accountant?year=2097&quarter=3';

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        // Primero la entrega: con el trimestre cerrado, la red no dejaría borrar lo demás.
        foreach ($em->getRepository(AccountantDelivery::class)->findBy(['year' => 2097]) as $delivery) {
            $em->remove($delivery);
        }
        $em->flush();
        static::getContainer()->get(ClosedPeriods::class)->reset();

        foreach ($em->getRepository(ReceivedInvoice::class)->findBy(['originalName' => self::FILE]) as $invoice) {
            $em->remove($invoice);
        }
        $em->flush();
        foreach (array_reverse($this->created) as [$class, $id]) {
            if (($row = $em->find($class, $id)) !== null) {
                $em->remove($row);
                $em->flush();
            }
        }
        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testElCircuitoDeUnTrimestre(): void
    {
        static::mockTime('2097-10-10 10:00');
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);

        // 1. La factura llega a la bandeja; el banco ya trajo su pago.
        $crawler = $client->request('GET', '/gestion/accounting/invoices');
        $upload = $crawler->filter('form[name="received_invoice_upload"]')->form();
        $upload['received_invoice_upload[files]'][0]->upload($this->samplePdf());
        $client->submit($upload);
        $bank = $this->bankPaymentAndReading();

        $client->request('GET', self::GESTORIA);
        $this->assertSelectorTextContains('body', 'Gastos sin factura');
        $this->assertSelectorTextContains('body', 'trf. ferreteria torrelaguna');

        // 2. Confirmarla la engancha al pago del banco: el apunte no se repite.
        $crawler = $client->request('GET', sprintf('/gestion/accounting/invoices/%d', $this->invoice()->getId()));
        $client->submit($crawler->filter('form[name="received_invoice_confirm"]')->form());
        $this->assertSame($bank, $this->invoice()->getAccountEntry()?->getId());

        // 3. Ya va en el paquete y deja de faltar.
        $client->request('GET', self::GESTORIA);
        $this->assertSelectorTextContains('body', 'Fact 2232');
        $this->assertSelectorTextNotContains('body', 'Gastos sin factura');

        // 4. Alguien deja abierta la ficha del apunte; entretanto, se entrega el trimestre.
        $stale = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $bank));
        $delete = $stale->filter(sprintf('form[action$="/entry/%d/delete"]', $bank))->form();
        $crawler = $client->request('GET', self::GESTORIA);
        $client->submit($crawler->selectButton('Marcar como entregado')->form());
        $client->followRedirect();

        // 5. La ficha dice que está cerrado y no ofrece cambiarlo; corregir ni se abre.
        $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $bank));
        $this->assertSelectorTextContains('body', 'El 3T2097 se entregó a la gestoría');
        $this->assertCount(0, $crawler->filter(sprintf('form[action$="/entry/%d/delete"]', $bank)));
        $client->request('GET', sprintf('/gestion/accounting/entry/%d/edit', $bank));
        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'El 3T2097 ya se entregó a la gestoría');

        // 6. Borrar desde la página de antes: no se borra, y no dice que sí.
        $client->submit($delete);
        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'El 3T2097 ya se entregó a la gestoría');
        $this->assertSelectorTextNotContains('body', 'Apunte borrado');
        $this->assertSame($bank, $this->invoice()->getAccountEntry()?->getId(), 'El apunte sigue, con su factura.');
    }

    /**
     * Lo que trae el banco tres días después de la factura, y la factura leída (en los
     * tests no hay lectura automática). Después de subir: cada petición reinicia el
     * kernel y su EntityManager, y todo tiene que vivir en el mismo.
     *
     * @return int El id del apunte del banco.
     */
    private function bankPaymentAndReading(): int
    {
        $invoice = $this->invoice();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(3));
        $account = (new FinancialAccount())->setName('Banco trimestre '.$suffix)
            ->setKind(FinancialAccount::KIND_BANK)->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2097-01-01'))
            ->setActive(true)->setSortOrder(99);
        $group = (new BudgetCategoryGroup())->setName('GASTOS TRIMESTRE '.$suffix)->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($group)->setName('Ferretería')->setSortOrder(1);
        $bank = (new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable('2097-09-12'))->setConcept('trf. ferreteria torrelaguna')->setAmount('-26.75');
        foreach ([$account, $group, $category, $bank] as $row) {
            $em->persist($row);
        }
        $invoice->markRead(ExtractedInvoice::fromArray([
            'fecha' => '2097-09-09', 'total' => 26.75, 'proveedor' => 'Ferretería Torrelaguna', 'numero_factura' => '2232',
        ]), 'test', $category, new \DateTimeImmutable());
        $em->flush();
        foreach ([$account, $group, $category, $bank] as $row) {
            $this->created[] = [$row::class, $row->getId()];
        }

        return $bank->getId();
    }

    private function invoice(): ReceivedInvoice
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(ReceivedInvoice::class)->findOneBy(['originalName' => self::FILE]);
    }

    /** Un PDF mínimo pero real: el formato se comprueba por el contenido. */
    private function samplePdf(): string
    {
        $path = sys_get_temp_dir().'/'.self::FILE;
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return $path;
    }
}
