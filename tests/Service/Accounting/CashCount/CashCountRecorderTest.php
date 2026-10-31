<?php

namespace App\Tests\Service\Accounting\CashCount;

use App\Entity\AccountantDelivery;
use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\CashCount;
use App\Entity\FinancialAccount;
use App\Service\Accounting\Agenda\AccountingAgenda;
use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\Accounting\CashCount\CashCountMessage;
use App\Service\Accounting\CashCount\CashCountRecorder;
use App\Service\Accounting\CashCount\CashCountRejected;
use App\Service\Accounting\CashCount\RecordedCashCount;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Telegram\Destination\CashCountDestination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Registrar un arqueo contra la base de verdad: con qué saldo del libro se compara
 * (los apuntes no llevan hora), cómo se encuentra la caja por su nombre, que un
 * mensaje repetido no duplica, y que la portada recalcula en vivo.
 *
 * Autocontenido: cajas propias abiertas en 2094 y el reloj en octubre de 2094, para
 * no chocar con nada de db_test ni con trimestres entregados de verdad.
 */
class CashCountRecorderTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const TODAY = '2094-10-31';

    private EntityManagerInterface $em;
    private CashCountRecorder $recorder;
    private string $tag;
    private FinancialAccount $garden;
    private BudgetCategory $category;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime(self::TODAY . ' 18:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->recorder = static::getContainer()->get(CashCountRecorder::class);
        $this->tag = 't' . bin2hex(random_bytes(3));

        $group = (new BudgetCategoryGroup())->setName('GASTOS ARQUEO ' . $this->tag)->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99);
        $this->category = (new BudgetCategory())->setGroup($group)->setName('Material de huerta')->setSortOrder(1);
        $this->garden = $this->account('Caja huerta ' . $this->tag, '300.00', '2094-01-01');
        $this->persist($group, $this->category);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->em->getRepository(AccountantDelivery::class)->findBy(['year' => 2094]) as $delivery) {
            $this->em->remove($delivery);
        }
        $this->em->flush();
        static::getContainer()->get(ClosedPeriods::class)->reset();

        $accounts = array_values(array_filter($this->created, static fn (object $o): bool => $o instanceof FinancialAccount));
        foreach ($this->em->getRepository(CashCount::class)->findBy(['account' => array_map(static fn (FinancialAccount $a) => $a->getId(), $accounts)]) as $count) {
            $this->em->remove($count);
        }
        $this->em->flush();
        foreach (array_reverse($this->created) as $entity) {
            $row = $this->em->find($entity::class, $entity->getId());
            if ($row !== null) {
                $this->em->remove($row);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    /** Una caja sin apuntes cuadra con su saldo de apertura: un solo saldo que enseñar. */
    public function testUnaCajaSinApuntesCuadraConSuApertura(): void
    {
        $recorded = $this->count('caja huerta %s 300');

        $this->assertTrue($recorded->squares());
        $this->assertFalse($recorded->balances->changesThatDay());
        $this->assertSame('300.00', $recorded->count->getBookBalance());
        $this->assertSame('0.00', $recorded->count->getDifference());
        $this->assertSame(self::TODAY, $recorded->count->getCountDate()->format('Y-m-d'));
        $this->assertSame(sprintf('Caja huerta %s, 31/10/2094: contados 300,00 €. En el libro: 300,00 €. Cuadra: queda registrado.', $this->tag), CashCountDestination::summarize($recorded));
    }

    /**
     * Con un ticket de hoy en la caja no se sabe si se contó antes o después de él:
     * cuadra con cualquiera de los dos saldos, y el bot enseña los dos.
     */
    public function testConApuntesDelDiaCuadraConElSaldoDeAntesODeDespues(): void
    {
        $this->entry('2094-10-20', '100.56', 'Venta de plantel en el mercadillo');
        $this->entry(self::TODAY, '-50.00', 'Ticket ferretería: mangueras');

        $before = $this->count('caja huerta %s 400,56');
        $this->assertTrue($before->squares());
        $this->assertSame('400.56', $before->count->getBookBalance(), 'el saldo que coincidía');
        $this->assertStringContainsString('En el libro: 400,56 € antes de lo anotado ese día y 350,56 € con ello. Cuadra', CashCountDestination::summarize($before));

        $after = $this->count('caja huerta %s 350,56');
        $this->assertTrue($after->squares());
        $this->assertSame('350.56', $after->count->getBookBalance());
    }

    /** Si no cuadra, la diferencia es con el cierre del día, con su signo, y queda registrado igual. */
    public function testSiNoCuadraQuedaRegistradoConLaDiferencia(): void
    {
        $this->entry(self::TODAY, '-50.00', 'Ticket ferretería: mangueras');

        $short = $this->count('caja huerta %s 238,40');
        $this->assertFalse($short->squares());
        $this->assertSame('250.00', $short->count->getBookBalance());
        $this->assertSame('-11.60', $short->count->getDifference());
        $this->assertStringContainsString('No cuadra: faltan 11,60 €.', CashCountDestination::summarize($short));
        $this->assertNotNull($short->count->getId());

        $over = $this->count('caja huerta %s 260');
        $this->assertSame('10.00', $over->count->getDifference());
        $this->assertStringContainsString('No cuadra: sobran 10,00 €.', CashCountDestination::summarize($over));
    }

    /** Lo anotado después del día del recuento no cuenta: el arqueo es de lo que había ese día. */
    public function testLosApuntesPosterioresAlRecuentoNoCuentan(): void
    {
        $this->entry(self::TODAY, '-20.00', 'Ticket gasolinera: desbrozadora');

        $recorded = $this->count('caja huerta %s 300 30/10');

        $this->assertTrue($recorded->squares());
        $this->assertSame('2094-10-30', $recorded->count->getCountDate()->format('Y-m-d'));
    }

    /** Si Telegram repite el mensaje (y el bot no lo recuerda), no sale un arqueo duplicado. */
    public function testElMismoMensajeDeTelegramNoDuplica(): void
    {
        $updateId = random_int(900_000_000, 999_999_999);

        $first = $this->count('caja huerta %s 300', $updateId);
        $this->em->clear();
        $again = $this->count('caja huerta %s 300', $updateId);

        $this->assertSame($first->count->getId(), $again->count->getId());
        $this->assertCount(1, $this->em->getRepository(CashCount::class)->findBy(['account' => $this->garden->getId()]));
        $this->assertSame($updateId, $again->count->getTelegramUpdateId());
    }

    /** El nombre se busca sin mayúsculas, sin tildes y sin «de la». */
    public function testElNombreSinMayusculasNiTildes(): void
    {
        $recorded = $this->count('CAJA de la Huérta ' . strtoupper($this->tag) . ' 300');

        $this->assertSame($this->garden->getId(), $recorded->count->getAccount()->getId());
    }

    /** Si el nombre vale para varias cajas, no se adivina: se contesta con ellas. */
    public function testUnNombreAmbiguoNoSeAdivina(): void
    {
        $this->account('Caja ' . $this->tag . ' norte', '0', '2094-01-01');
        $this->account('Caja ' . $this->tag . ' sur', '0', '2094-01-01');

        $message = $this->rejection('caja %s 10');

        $this->assertStringContainsString('Caja ' . $this->tag . ' norte', $message);
        $this->assertStringContainsString('Caja ' . $this->tag . ' sur', $message);
        $this->assertCount(0, $this->em->getRepository(CashCount::class)->findBy(['account' => $this->garden->getId()]));
    }

    /** Si una caja lleva el nombre justo y otra lo lleva con algo más, es la justa. */
    public function testElNombreJustoGanaAlQueLoContiene(): void
    {
        $this->account('Caja huerta ' . $this->tag . ' vieja', '0', '2094-01-01');

        $this->assertSame($this->garden->getId(), $this->count('caja huerta %s 300')->count->getAccount()->getId());
    }

    /** Una caja que no existe, o una cuenta que no es caja: no hay arqueo, y se dicen las cajas abiertas. */
    public function testSinCajaConEseNombreSeListanLasAbiertas(): void
    {
        $bank = $this->account('Banco ' . $this->tag, '0', '2094-01-01', FinancialAccount::KIND_BANK);

        $none = $this->rejection('caja %s inventada 10');
        $this->assertStringContainsString('No tengo ninguna caja que se llame', $none);
        $this->assertStringContainsString('«Caja huerta ' . $this->tag . '»', $none);

        $notCash = $this->rejection('caja banco %s 10');
        $this->assertStringContainsString('No tengo ninguna caja', $notCash);
        $this->assertStringNotContainsString($bank->getName() . '»', $notCash);
    }

    /** Una caja retirada ya no se cuenta: se dice, con las que siguen abiertas. */
    public function testUnaCajaRetiradaSeRechaza(): void
    {
        $this->account('Caja local ' . $this->tag, '0', '2094-01-01')->setActive(false);
        $this->em->flush();

        $message = $this->rejection('caja local %s 0');

        $this->assertStringContainsString('está retirada', $message);
        $this->assertStringContainsString('«Caja huerta ' . $this->tag . '»', $message);
    }

    /** Antes de que la caja se llevase aquí no hay libro con el que comparar. */
    public function testAntesDeLaAperturaSeRechaza(): void
    {
        $this->account('Caja local ' . $this->tag, '0', '2094-06-01');

        $this->assertStringContainsString('desde el 01/06/2094', $this->rejection('caja local %s 0 31/05'));
    }

    /** Con el trimestre entregado a la gestoría, el arqueo se registra igual: no es un apunte. */
    public function testConElTrimestreEntregadoSeRegistraIgual(): void
    {
        static::getContainer()->get(ClosedPeriods::class)->deliver(InvoiceQuarter::of(2094, 3), null);

        $recorded = $this->count('caja huerta %s 300 30/09');

        $this->assertNotNull($recorded->count->getId());
        $this->assertTrue($recorded->squares());
    }

    /**
     * La portada lo avisa mientras no cuadre y lo recalcula en vivo: al anotar después
     * el ticket olvidado, con fecha anterior al arqueo, el aviso desaparece solo.
     */
    public function testLaPortadaAvisaYElTicketOlvidadoLoQuita(): void
    {
        $this->count('caja huerta %s 276,60 30/10');

        $item = $this->agendaItem();
        $this->assertNotNull($item, 'no cuadra: la portada lo avisa');
        $this->assertSame(sprintf('El arqueo de Caja huerta %s del 30/10/2094 no cuadra: -23,40 €', $this->tag), $item->title);
        $this->assertSame('accounting_entry_new', $item->route);
        $this->assertSame(['account' => $this->garden->getId(), 'date' => '2094-10-30'], $item->routeParams);
        $this->assertCount(2, $item->details);

        $this->entry('2094-10-12', '-23.40', 'Ticket semillas sin anotar');

        $this->assertNull($this->agendaItem(), 'con el ticket anotado ya cuadra');
    }

    /** Si se contó mal y se manda otro el mismo día, vale el último. */
    public function testDelMismoDiaValeElUltimo(): void
    {
        $this->count('caja huerta %s 280 30/10');
        $this->assertNotNull($this->agendaItem());

        $this->count('caja huerta %s 300 30/10');

        $this->assertNull($this->agendaItem());
    }

    /** Un arqueo posterior que cuadra sustituye a uno anterior que no. */
    public function testUnArqueoPosteriorSustituyeAlAnterior(): void
    {
        $this->count('caja huerta %s 280 30/09');
        $this->assertNotNull($this->agendaItem());

        $this->count('caja huerta %s 300 30/10');

        $this->assertNull($this->agendaItem());
    }

    /** Registra un arqueo; `%s` en el texto es la etiqueta de las cajas del test. */
    private function count(string $text, ?int $updateId = null): RecordedCashCount
    {
        $text = sprintf($text, $this->tag);

        return $this->recorder->record(
            CashCountMessage::parse($text, new \DateTimeImmutable(self::TODAY)),
            null,
            new \DateTimeImmutable(self::TODAY . ' 18:00'),
            $text,
            $updateId,
        );
    }

    /** Lo que se le contesta a un arqueo que no se registra. */
    private function rejection(string $text): string
    {
        try {
            $this->count($text);
        } catch (CashCountRejected $e) {
            return $e->getMessage();
        }
        $this->fail('Se ha registrado un arqueo que debía rechazarse.');
    }

    /** El aviso de la portada sobre la caja de la huerta del test, o null si no hay. */
    private function agendaItem(): ?AgendaItem
    {
        $items = array_values(array_filter(
            static::getContainer()->get(AccountingAgenda::class)->items(new \DateTimeImmutable(self::TODAY)),
            fn (AgendaItem $i): bool => str_contains($i->title, 'Caja huerta ' . $this->tag . ' '),
        ));
        $this->assertLessThanOrEqual(1, \count($items));

        return $items[0] ?? null;
    }

    private function account(string $name, string $opening, string $openedOn, int $kind = FinancialAccount::KIND_CASH): FinancialAccount
    {
        $account = (new FinancialAccount())->setName($name)->setKind($kind)->setOpeningBalance($opening)
            ->setOpeningDate(new \DateTimeImmutable($openedOn))->setActive(true)->setSortOrder(99);
        $this->persist($account);

        return $account;
    }

    private function entry(string $date, string $amount, string $concept): void
    {
        $this->persist((new AccountEntry())
            ->setAccount($this->em->find(FinancialAccount::class, $this->garden->getId()))
            ->setCategory($this->em->find(BudgetCategory::class, $this->category->getId()))
            ->setDate(new \DateTimeImmutable($date))
            ->setConcept($concept)
            ->setAmount($amount));
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
            $this->created[] = $entity;
        }
        $this->em->flush();
    }
}
