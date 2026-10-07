<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\ReceivedInvoice;
use App\Repository\BudgetCategoryRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use App\Service\Accounting\Invoice\GeminiInvoiceReader;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\Invoice\InvoiceReadQueue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * La cola contra la base de verdad: que una factura leída queda propuesta con su
 * partida, que un fallo pasajero la aplaza sin perderla, que uno definitivo la manda
 * a mano, y que una factura ya reservada no se lee dos veces.
 */
class InvoiceReadQueueTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BudgetCategoryGroup $group;
    private BudgetCategory $category;

    /** @var list<ReceivedInvoice> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->group = (new BudgetCategoryGroup())->setName('GASTOS TEST COLA ' . bin2hex(random_bytes(3)))->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99);
        $this->category = (new BudgetCategory())->setGroup($this->group)->setName('Ferretería de prueba')->setSortOrder(1);
        $this->em->persist($this->group);
        $this->em->persist($this->category);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $invoice) {
            $invoice = $this->em->find(ReceivedInvoice::class, $invoice->getId());
            if ($invoice !== null) {
                $this->em->remove($invoice);
            }
        }
        $this->em->flush();
        $this->em->remove($this->em->find(BudgetCategory::class, $this->category->getId()));
        $this->em->remove($this->em->find(BudgetCategoryGroup::class, $this->group->getId()));
        $this->em->flush();

        parent::tearDown();
    }

    public function testUnaFacturaLeidaQuedaPropuestaConLaPartidaQueEligioLaLectura(): void
    {
        $invoice = $this->newInvoice();
        $queue = $this->queue([$this->ok(['proveedor' => 'Ferretería Torrelaguna', 'cif_proveedor' => 'X' . random_int(1000000, 9999999) . 'Z', 'total' => 26.75, 'forma_pago' => 'tarjeta', 'tipo' => 'factura', 'confianza' => 'alta', 'partida_id' => $this->category->getId()])]);

        $done = $queue->process(10, [$invoice->getId()]);

        $this->assertSame(1, $done['read']);
        $invoice = $this->reload($invoice);
        $this->assertSame(ReceivedInvoice::STATUS_READ, $invoice->getStatus());
        $this->assertSame('26.75', $invoice->getTotal());
        $this->assertSame($this->category->getId(), $invoice->getSuggestedCategory()?->getId());
        $this->assertSame(1, $invoice->getAttempts());
    }

    /** Una partida que no es de gasto (o que no existe) no se propone aunque la lectura la elija. */
    public function testUnaPartidaFueraDelCatalogoNoSePropone(): void
    {
        $invoice = $this->newInvoice();
        $queue = $this->queue([$this->ok(['proveedor' => 'Proveedor sin historial ' . bin2hex(random_bytes(3)), 'total' => 10, 'forma_pago' => 'efectivo', 'tipo' => 'ticket', 'confianza' => 'alta', 'partida_id' => 987654])]);

        $queue->process(10, [$invoice->getId()]);

        $this->assertNull($this->reload($invoice)->getSuggestedCategory());
    }

    /** Servicio saturado: la factura sigue en cola, con el motivo y la hora del siguiente intento. */
    public function testUnFalloPasajeroLaAplazaSinPerderla(): void
    {
        $invoice = $this->newInvoice();
        $queue = $this->queue([new MockResponse('', ['http_code' => 503])]);

        $done = $queue->process(10, [$invoice->getId()]);

        $this->assertSame(1, $done['postponed']);
        $invoice = $this->reload($invoice);
        $this->assertSame(ReceivedInvoice::STATUS_PENDING, $invoice->getStatus());
        $this->assertNotNull($invoice->getLastError());
        $this->assertGreaterThan(new \DateTimeImmutable('+50 minutes'), $invoice->getNextAttemptAt());

        // Aplazada, no se vuelve a intentar antes de su hora.
        $this->assertSame(['read' => 0, 'postponed' => 0, 'unreadable' => 0], $this->queue([])->process(10, [$invoice->getId()]));
    }

    /** Un documento que el servicio no puede leer va directo a mano: insistir sólo gasta cupo. */
    public function testUnFalloDefinitivoLaMandaAMano(): void
    {
        $invoice = $this->newInvoice();
        $queue = $this->queue([new MockResponse('', ['http_code' => 400])]);

        $done = $queue->process(10, [$invoice->getId()]);

        $this->assertSame(1, $done['unreadable']);
        $this->assertSame(ReceivedInvoice::STATUS_UNREADABLE, $this->reload($invoice)->getStatus());
    }

    /**
     * La lectura inmediata y la del planificador pueden coincidir. La reserva hace
     * que la segunda no la lea otra vez (y no gaste cupo otra vez).
     */
    public function testUnaFacturaReservadaNoSeLeeDosVeces(): void
    {
        $invoice = $this->newInvoice();
        /** @var ReceivedInvoiceRepository $repo */
        $repo = static::getContainer()->get(ReceivedInvoiceRepository::class);
        $now = new \DateTimeImmutable();

        $this->assertTrue($repo->claim($invoice, $now, $now->modify('+10 minutes')));
        $this->assertFalse($repo->claim($invoice, $now, $now->modify('+10 minutes')), 'Dos lectores han reservado la misma factura.');
    }

    /** Sin clave configurada la cola no toca nada: no gasta intentos de las facturas. */
    public function testSinClaveLaColaNoGastaIntentos(): void
    {
        $invoice = $this->newInvoice();
        $queue = $this->queue([], '');

        $this->assertFalse($queue->isEnabled());
        $queue->process(10, [$invoice->getId()]);

        $this->assertSame(0, $this->reload($invoice)->getAttempts());
    }

    /**
     * La misma factura entregada dos veces (por el bot y subida del correo) se
     * reconoce por proveedor y número, para avisar antes de contar el gasto doble.
     */
    public function testUnaFacturaYaAnotadaSeReconoceAlRevisarSuGemela(): void
    {
        $taxId = 'B' . random_int(10000000, 99999999);
        $read = ExtractedInvoice::fromArray(['proveedor' => 'Hnos. Iglesias', 'cif_proveedor' => $taxId, 'numero_factura' => 'FA37', 'total' => 77.21]);

        $account = (new FinancialAccount())->setName('Banco gemelas ' . bin2hex(random_bytes(3)))->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2026-01-01'))->setActive(true)->setSortOrder(99);
        $entry = (new AccountEntry())->setDate(new \DateTimeImmutable('2026-04-07'))->setAccount($account)->setCategory($this->category)
            ->setConcept('Gasolina')->setAmount('-77.21')->setInvoiceNumber('FA37');
        $this->em->persist($account);
        $this->em->persist($entry);

        $first = $this->newInvoice();
        $first->markRead($read, 'modelo-a', null, new \DateTimeImmutable());
        $first->confirm($entry, null);
        $second = $this->newInvoice();
        $second->markRead($read, 'modelo-a', null, new \DateTimeImmutable());
        $this->em->flush();

        $repo = static::getContainer()->get(ReceivedInvoiceRepository::class);
        $this->assertSame($first->getId(), $repo->findAlreadyConfirmedTwin($second)?->getId());
        $this->assertNull($repo->findAlreadyConfirmedTwin($first), 'Una factura no es gemela de sí misma.');

        // Limpieza de lo que no limpia tearDown.
        $this->em->remove($first);
        $this->em->remove($second);
        $this->em->flush();
        $this->em->remove($entry);
        $this->em->flush();
        $this->em->remove($account);
        $this->em->flush();
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function queue(array $responses, string $key = 'clave'): InvoiceReadQueue
    {
        $c = static::getContainer();

        return new InvoiceReadQueue(
            new GeminiInvoiceReader(new MockHttpClient($responses), $key, ['modelo-a']),
            $c->get(InvoiceFileStore::class),
            $c->get(ReceivedInvoiceRepository::class),
            $c->get(BudgetCategoryRepository::class),
            $this->em,
            new NullLogger(),
            static function (int $seconds): void {},
        );
    }

    private function newInvoice(): ReceivedInvoice
    {
        $path = sys_get_temp_dir() . '/cola-test-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        $name = static::getContainer()->get(InvoiceFileStore::class)->store(new UploadedFile($path, 'factura.pdf', 'application/pdf', null, true));

        $invoice = new ReceivedInvoice($name, 'factura.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $this->em->persist($invoice);
        $this->em->flush();
        $this->created[] = $invoice;

        return $invoice;
    }

    private function reload(ReceivedInvoice $invoice): ReceivedInvoice
    {
        $this->em->clear();

        return $this->em->find(ReceivedInvoice::class, $invoice->getId());
    }

    private function ok(array $data): MockResponse
    {
        return new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]]]],
        ]), ['http_code' => 200]);
    }
}
