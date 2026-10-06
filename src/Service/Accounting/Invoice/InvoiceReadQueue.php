<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\ReceivedInvoice;
use App\Repository\BudgetCategoryRepository;
use App\Repository\ReceivedInvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * La cola de lectura de facturas: coge las pendientes, las lee y deja el resultado.
 *
 * No hay proceso esperando trabajo (el hosting no permite procesos permanentes): la
 * cola es la tabla, y la recorren dos cosas. Justo después de subir una factura, la
 * propia petición la intenta leer cuando ya ha contestado al navegador, así que lo
 * normal es verla leída en segundos. Lo que falle lo recoge cada hora el
 * planificador. Las dos pueden coincidir sin leer nada dos veces: cada factura se
 * reserva con una sentencia atómica antes de leerla.
 */
class InvoiceReadQueue
{
    /** Lo que dura la reserva de una factura mientras se lee. */
    private const LEASE = '+10 minutes';

    /** Cuánto esperar tras un fallo pasajero. Coincide con el paso del planificador. */
    private const RETRY_AFTER = '+1 hour';

    /**
     * Intentos antes de rendirse: dos días de reintentos horarios. Una caída de
     * Google de una tarde no manda nada a mano; una factura que lleva dos días sin
     * salir sí, porque algo le pasa que esperar no arregla.
     */
    public const MAX_ATTEMPTS = 48;

    /**
     * Pausa entre lecturas de una misma pasada. El plan gratuito limita las
     * peticiones por minuto (15 en el modelo principal), y subir veinte facturas de
     * golpe no debe tumbar las últimas por exceso de ritmo.
     */
    private const PAUSE_SECONDS = 4;

    /**
     * @param GeminiInvoiceReader       $reader     Quien lee.
     * @param InvoiceFileStore          $files      Dónde están los ficheros.
     * @param ReceivedInvoiceRepository $invoices   Las facturas.
     * @param BudgetCategoryRepository  $categories Las partidas.
     * @param EntityManagerInterface    $em         Para guardar.
     * @param LoggerInterface           $logger     Rastro de lo que falla.
     * @param \Closure|null             $sleep      Cómo esperar entre lecturas (los tests no esperan).
     */
    public function __construct(
        private readonly GeminiInvoiceReader $reader,
        private readonly InvoiceFileStore $files,
        private readonly ReceivedInvoiceRepository $invoices,
        private readonly BudgetCategoryRepository $categories,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $sleep = null,
    ) {
    }

    /** Si hay con qué leer. Sin clave, la cola espera sin gastar intentos. */
    public function isEnabled(): bool
    {
        return $this->reader->isConfigured();
    }

    /**
     * Lee lo que toque.
     *
     * @param int            $limit Cuántas como mucho en esta pasada.
     * @param list<int>|null $ids   Sólo éstas (las que se acaban de subir).
     *
     * @return array{read: int, postponed: int, unreadable: int} Recuento de la pasada.
     */
    public function process(int $limit, ?array $ids = null): array
    {
        $done = ['read' => 0, 'postponed' => 0, 'unreadable' => 0];
        if (!$this->isEnabled()) {
            return $done;
        }

        $catalogue = $this->catalogue();
        $first = true;

        foreach ($this->invoices->findDue(new \DateTimeImmutable(), $limit, $ids) as $invoice) {
            if (!$first) {
                ($this->sleep ?? static fn (int $s) => sleep($s))(self::PAUSE_SECONDS);
            }
            $first = false;

            $now = new \DateTimeImmutable();
            if (!$this->invoices->claim($invoice, $now, $now->modify(self::LEASE))) {
                continue; // La ha cogido otro lector.
            }
            $this->em->refresh($invoice);

            ++$done[$this->readOne($invoice, $catalogue)];
            $this->em->flush();
        }

        return $done;
    }

    /**
     * Lee una factura ya reservada y deja anotado cómo ha ido.
     *
     * @param ReceivedInvoice                $invoice   Factura reservada.
     * @param array<int, BudgetCategory>     $catalogue Partidas que se pueden proponer, por id.
     *
     * @return 'read'|'postponed'|'unreadable'
     */
    private function readOne(ReceivedInvoice $invoice, array $catalogue): string
    {
        $path = $this->files->pathTo($invoice->getFileName());
        if ($path === null) {
            $invoice->markUnreadable('No se encuentra el fichero en el servidor.');

            return 'unreadable';
        }

        try {
            $reading = $this->reader->read(
                $path,
                $invoice->getMimeType(),
                array_map(static fn (BudgetCategory $c): string => sprintf('%s / %s', $c->getGroup()?->getName(), $c->getName()), $catalogue),
            );
        } catch (InvoiceReadException $e) {
            $this->logger->warning('Factura {id} sin leer: {reason}', ['id' => $invoice->getId(), 'reason' => $e->getMessage()]);

            if (!$e->isRetryable() || $invoice->getAttempts() >= self::MAX_ATTEMPTS) {
                $invoice->markUnreadable($e->getMessage());

                return 'unreadable';
            }

            $invoice->postpone($e->getMessage(), new \DateTimeImmutable(self::RETRY_AFTER));

            return 'postponed';
        }

        $data = $reading->invoice;
        // Lo que se hizo otras veces con este proveedor manda sobre lo que proponga
        // la lectura: es la decisión que tomó una persona. Las dos pasan por el
        // catálogo, para no proponer una partida retirada o de ingresos.
        $usual = $this->invoices->usualCategoryFor($data->providerTaxId, $data->providerName);
        $category = $catalogue[(int) $usual?->getId()]
            ?? ($data->categoryId !== null ? ($catalogue[$data->categoryId] ?? null) : null);

        $invoice->markRead($data, $reading->model, $category, new \DateTimeImmutable());

        return 'read';
    }

    /**
     * Partidas que una factura recibida puede llevar: las de gasto y las de
     * inversión, activas. Indexadas por id para validar lo que proponga la lectura:
     * un id que no esté aquí se descarta.
     *
     * @return array<int, BudgetCategory>
     */
    private function catalogue(): array
    {
        $out = [];
        foreach ($this->categories->findActive() as $category) {
            $kind = $category->getGroup()?->getKind();
            if (\in_array($kind, [BudgetCategoryGroup::KIND_EXPENSE, BudgetCategoryGroup::KIND_INVESTMENT], true)) {
                $out[(int) $category->getId()] = $category;
            }
        }

        return $out;
    }
}
