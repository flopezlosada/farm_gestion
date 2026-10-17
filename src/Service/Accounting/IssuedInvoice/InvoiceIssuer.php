<?php

namespace App\Service\Accounting\IssuedInvoice;

use App\Entity\IssuedInvoice;
use App\Entity\User;
use App\Repository\IssuedInvoiceRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Emite un borrador: le da el siguiente número de su serie y año y lo cierra.
 *
 * Existe porque el número es lo único que no se puede equivocar. Correlativo y sin
 * huecos dentro de cada serie y año, y nunca repetido aunque dos personas emitan a
 * la vez: el último número se lee con las filas bloqueadas y la factura también,
 * todo en una transacción. Por si algo se colase, la base de datos tiene además un
 * índice único sobre serie, año y número.
 *
 * El PDF se genera y se guarda dentro de esa misma transacción: si falla, la
 * factura no queda emitida, y no hay factura emitida sin su PDF.
 */
class InvoiceIssuer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssuedInvoiceRepository $invoices,
        private readonly IssuedInvoicePdf $pdf,
        private readonly IssuedInvoiceArchive $archive,
    ) {
    }

    /**
     * La emite, o dice por qué no.
     *
     * Transacción a mano y no wrapInTransaction(), que cierra el EntityManager ante
     * cualquier excepción y dejaría la página sin poder pintar el error.
     *
     * @param IssuedInvoice $invoice Un borrador ya guardado.
     * @param User|null     $by      Quién la emite.
     *
     * @return string|null Null si se ha emitido; si no, por qué no.
     */
    public function issue(IssuedInvoice $invoice, ?User $by): ?string
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            // Con la factura bloqueada: dos clics en «Emitir» no le dan dos números.
            $this->em->refresh($invoice, LockMode::PESSIMISTIC_WRITE);
            if ($invoice->isIssued()) {
                $connection->rollBack();

                return sprintf('Esta factura ya estaba emitida con el número %s.', $invoice->getNumberLabel());
            }
            // La serie se bloquea antes de mirar la fecha de su última emitida: así
            // nadie emite otra más reciente entre la comprobación y el número.
            $date = $invoice->getIssueDate();
            $last = $date !== null ? $this->invoices->lastNumberLocked($invoice->getSeries(), (int) $date->format('Y')) : 0;
            if (($problems = $this->problems($invoice)) !== []) {
                $connection->rollBack();

                return implode(' ', $problems);
            }

            $next = $last + 1;
            $invoice->issue($next, $by, new \DateTimeImmutable());
            $this->em->flush();
            $this->archive->store($invoice, $this->pdf->render($invoice));
            $connection->commit();

            return null;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Lo que impide emitirla: lo que le falta a la propia factura, los datos de la
     * asociación y que su fecha no sea anterior a la de la última emitida de su
     * serie y año. Esto último porque el número es correlativo: una de junio
     * emitida después de una de septiembre llevaría un número mayor con una fecha
     * anterior, y número y fecha tienen que ir en el mismo orden.
     *
     * @return list<string> Vacía si se puede emitir.
     */
    public function problems(IssuedInvoice $invoice): array
    {
        $problems = $invoice->issueProblems();
        if (!$this->pdf->hasIssuer()) {
            $problems[] = 'Faltan los datos de la asociación (razón social, CIF y dirección): se ponen en Configuración, «Facturas emitidas».';
        }

        $date = $invoice->getIssueDate();
        $last = $date !== null ? $this->invoices->findLastIssued($invoice->getSeries(), (int) $date->format('Y')) : null;
        if ($last !== null && $date < $last->getIssueDate()) {
            $problems[] = sprintf(
                'La última emitida de la serie, la %s, es del %s: esta no puede llevar una fecha anterior, porque su número iría detrás. Ponle esa fecha o una posterior.',
                $last->getNumberLabel(),
                $last->getIssueDate()->format('d/m/Y'),
            );
        }

        return $problems;
    }
}
