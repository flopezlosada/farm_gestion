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
 */
class InvoiceIssuer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssuedInvoiceRepository $invoices,
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
            if (($problems = $invoice->issueProblems()) !== []) {
                $connection->rollBack();

                return implode(' ', $problems);
            }

            $year = (int) $invoice->getIssueDate()->format('Y');
            $next = $this->invoices->lastNumberLocked($invoice->getSeries(), $year) + 1;
            $invoice->issue($next, $by, new \DateTimeImmutable());
            $this->em->flush();
            $connection->commit();

            return null;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }
}
