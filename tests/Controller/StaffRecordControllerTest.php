<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Entity\WorkMonthClose;
use App\Service\AppSettings;
use App\Service\Staff\WorkMonth;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El registro de jornada para la Inspección de punta a punta: quién entra, qué
 * dicen los dos CSV, que el PDF sale de un año y que uno de varios años se
 * reparte por años en vez de tumbar el servidor.
 *
 * Se trabaja con el MES ANTERIOR al de hoy: entero en el pasado, así que todos
 * sus días cuentan pase el test el día que pase.
 */
class StaffRecordControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private WorkMonth $month;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_LABORAL, true);

        $admin = $this->em->getRepository(User::class)->loadUserByIdentifier('admin');
        if ($admin === null) {
            throw new \RuntimeException('Fixtures sin User admin; carga UserFixtures en db_test.');
        }
        $this->admin = $admin;
        $this->client->loginUser($admin);

        $this->month = WorkMonth::containing(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->previous();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(WorkMonthClose::class)->findAll() as $c) {
            $em->remove($c);
        }
        foreach ($em->getRepository(TimeEntry::class)->findAll() as $e) {
            $em->remove($e);
        }
        $em->flush();
        foreach ($em->getRepository(Worker::class)->findAll() as $w) {
            $em->remove($w);
        }
        foreach ($em->getRepository(Setting::class)->findAll() as $s) {
            $em->remove($s);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testSinPeriodoNoOfreceDescargas(): void
    {
        $crawler = $this->client->request('GET', '/gestion/staff/record');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('input[type="month"][name="from"]'));
        $this->assertCount(0, $crawler->filter('a[href*="/gestion/staff/record/download/"]'));
    }

    /** Dos años y pico: los CSV del periodo entero y un PDF por cada año. */
    public function testUnPeriodoLargoOfreceUnPdfPorAno(): void
    {
        $crawler = $this->client->request('GET', '/gestion/staff/record?from=2024-03&to=2026-02');

        $this->assertResponseIsSuccessful();
        $pdfs = $crawler->filter('a[href*="/download/pdf"]')->each(static fn ($a) => $a->attr('href'));
        $this->assertSame([
            '/gestion/staff/record/download/pdf?from=2024-03&to=2024-12',
            '/gestion/staff/record/download/pdf?from=2025-01&to=2025-12',
            '/gestion/staff/record/download/pdf?from=2026-01&to=2026-02',
        ], $pdfs);
        $this->assertCount(1, $crawler->filter('a[href="/gestion/staff/record/download/daily?from=2024-03&to=2026-02"]'));
    }

    public function testUnPeriodoAlRevesSeExplica(): void
    {
        $crawler = $this->client->request('GET', '/gestion/staff/record?from=2026-05&to=2026-01');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('después de terminar', $crawler->filter('.csa-callout')->text());
        $this->assertCount(0, $crawler->filter('a[href*="/download/"]'));
    }

    /**
     * Un renglón por tramo, y entran el activo y el inactivo que fichó en el
     * periodo; el inactivo sin fichajes no. Un nombre que empieza por «=» sale
     * escapado para que la hoja de cálculo no lo tome por fórmula.
     */
    public function testCsvDiario(): void
    {
        $active = $this->worker('=Activa', true);
        $this->punch($active, TimeEntry::TYPE_IN, '09:00');
        $this->punch($active, TimeEntry::TYPE_OUT, '14:00');
        $this->punch($active, TimeEntry::TYPE_IN, '15:00');
        $this->punch($active, TimeEntry::TYPE_OUT, '17:30');
        $gone = $this->worker('Inactiva que fichó', false);
        $this->punch($gone, TimeEntry::TYPE_IN, '08:00');
        $this->punch($gone, TimeEntry::TYPE_OUT, '12:00');
        $this->worker('Inactiva sin fichajes', false);

        $csv = $this->download('daily');

        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $day = sprintf('10/%02d/%d', $this->month->month, $this->month->year);
        // Registros diferidos en el test (se registran hoy por un día pasado):
        // el final del renglón lleva «Registro diferido», y quizá un festivo.
        $this->assertStringContainsString(sprintf("\n'=Activa;%s;09:00;14:00;5:00;7:30;Trabajado;", $day), $csv);
        $this->assertStringContainsString(sprintf("\n'=Activa;%s;15:00;17:30;2:30;7:30;Trabajado;", $day), $csv);
        $this->assertStringContainsString(sprintf("\n\"Inactiva que fichó\";%s;08:00;12:00;4:00;4:00;Trabajado;", $day), $csv);
        $this->assertStringNotContainsString('Inactiva sin fichajes', $csv);
        $this->assertSame(1, substr_count($csv, 'Trabajador/a;Fecha;'));
    }

    /**
     * Cada renglón dice si su mes está aprobado; una aprobación reabierta ya no
     * cuenta.
     */
    public function testCsvDiarioDiceSiElMesEstaAprobado(): void
    {
        $approved = $this->worker('Aprobada', true);
        $this->punch($approved, TimeEntry::TYPE_IN, '09:00');
        $this->em->persist(new WorkMonthClose($approved, $this->month, $this->admin, new \DateTimeImmutable('2026-01-15 10:00', new \DateTimeZone('Europe/Madrid'))));
        $reopened = $this->worker('Reabierta', true);
        $this->punch($reopened, TimeEntry::TYPE_IN, '09:00');
        $close = new WorkMonthClose($reopened, $this->month, $this->admin, new \DateTimeImmutable('2026-01-15 10:00'));
        $close->reopen($this->admin, new \DateTimeImmutable());
        $this->em->persist($close);
        $this->em->flush();

        $lines = explode("\n", $this->download('daily'));

        $ofApproved = array_filter($lines, static fn (string $l) => str_starts_with($l, 'Aprobada;'));
        $ofReopened = array_filter($lines, static fn (string $l) => str_starts_with($l, 'Reabierta;'));
        $this->assertNotEmpty($ofApproved);
        $this->assertNotEmpty($ofReopened);
        foreach ($ofApproved as $line) {
            $this->assertStringEndsWith(';15/01/2026', $line);
        }
        foreach ($ofReopened as $line) {
            $this->assertStringEndsWith(';', $line);
        }
    }

    /**
     * Un inactivo sólo tiene registro hasta el mes de su último fichaje: sin
     * fecha de baja, los meses siguientes saldrían enteros como «Sin fichar».
     */
    public function testUnInactivoNoSaleDespuesDeSuUltimoFichaje(): void
    {
        $gone = $this->worker('Se fue', false);
        $before = $this->month->previous();
        $entry = (new TimeEntry())
            ->setWorker($gone)
            ->setType(TimeEntry::TYPE_IN)
            ->setOccurredAt(new \DateTimeImmutable($before->key() . '-10 09:00', new \DateTimeZone('Europe/Madrid')))
            ->setSource(TimeEntry::SOURCE_SELF);
        $this->em->persist($entry);
        $this->em->flush();

        $this->client->request('GET', sprintf('/gestion/staff/record/download/daily?from=%s&to=%s', $before->key(), $this->month->key()));
        $csv = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString(sprintf("Se fue\";10/%02d/%d;09:00", $before->month, $before->year), $csv);
        $this->assertStringNotContainsString(sprintf('/%02d/%d;', $this->month->month, $this->month->year), $csv);
    }

    /** La traza lleva también lo anulado: quién lo anuló y por qué. */
    public function testCsvDeTrazaConLoAnulado(): void
    {
        $worker = $this->worker('Trabajadora de traza', true);
        $this->punch($worker, TimeEntry::TYPE_IN, '09:00');
        $wrong = $this->punch($worker, TimeEntry::TYPE_OUT, '19:00');
        $wrong->void($this->admin, 'Se le olvidó fichar la salida', new \DateTimeImmutable());
        $this->em->flush();

        $csv = $this->download('trail');

        $this->assertResponseIsSuccessful();
        $lines = array_values(array_filter(explode("\n", $csv), static fn (string $l) => str_contains($l, 'Trabajadora de traza')));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString(';Salida;', $lines[1]);
        $this->assertStringContainsString($this->admin->getDisplayName(), $lines[1]);
        $this->assertStringContainsString('"Se le olvidó fichar la salida"', $lines[1]);
        $this->assertStringEndsWith(';;', $lines[0], 'Lo vigente no tiene anulación.');
    }

    public function testPdfDeUnAno(): void
    {
        $this->punch($this->worker('Trabajadora del PDF', true), TimeEntry::TYPE_IN, '09:00');

        $pdf = $this->download('pdf');

        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString(sprintf('registro-jornada-%s.pdf', $this->month->key()), (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    /** Un PDF de varios años no se genera: vuelve a la pantalla, que lo ofrece año a año. */
    public function testPdfDeVariosAnosVuelveALaPantalla(): void
    {
        $this->client->request('GET', '/gestion/staff/record/download/pdf?from=2024-01&to=2025-12');

        $this->assertResponseRedirects('/gestion/staff/record?from=2024-01&to=2025-12');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'año a año');
    }

    /**
     * Descarga el formato pedido del mes revisado.
     *
     * @param string $format 'pdf', 'daily' o 'trail'.
     * @return string El contenido.
     */
    private function download(string $format): string
    {
        $key = $this->month->key();
        $this->client->request('GET', sprintf('/gestion/staff/record/download/%s?from=%s&to=%s', $format, $key, $key));
        $this->assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function worker(string $name, bool $active): Worker
    {
        $worker = (new Worker())
            ->setName($name)
            ->setHireDate(new \DateTimeImmutable('2020-01-01'))
            ->setActive($active)
            ->setAnnualVacationDays(22);
        $this->em->persist($worker);
        $this->em->flush();

        return $worker;
    }

    /**
     * Un fichaje el día 10 del mes revisado.
     */
    private function punch(Worker $worker, string $type, string $time): TimeEntry
    {
        $entry = (new TimeEntry())
            ->setWorker($worker)
            ->setType($type)
            ->setOccurredAt(new \DateTimeImmutable(sprintf('%s-10 %s', $this->month->key(), $time), new \DateTimeZone('Europe/Madrid')))
            ->setSource(TimeEntry::SOURCE_SELF);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
