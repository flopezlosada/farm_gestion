<?php

namespace App\Controller;

use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Invoice\AccountantPackage;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La entrega trimestral a la gestoría: una pantalla que enseña qué va a ir (y qué
 * falta o no cuadra) antes de descargarlo, y la descarga en un ZIP.
 *
 * Basta con poder ver la contabilidad: no cambia nada, sólo empaqueta lo anotado.
 */
#[Route('/gestion/accounting/invoices/accountant')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_GESTION_CONTABILIDAD')]
class AccountantExportController extends AbstractController
{
    /**
     * Lo que se entregaría del trimestre elegido; por defecto, el último cerrado,
     * que es el que toca entregar.
     */
    #[Route('', name: 'accounting_accountant_export', methods: ['GET'])]
    public function index(
        Request $request,
        AccountantPackage $package,
        InvoiceFileStore $files,
        ReceivedInvoiceRepository $invoices,
    ): Response {
        $quarter = $this->quarterFrom($request);
        $rows = array_map(static fn ($invoice) => [
            'invoice' => $invoice,
            'warnings' => AccountantPackage::warnings($invoice, $files->pathTo($invoice->getFileName())),
        ], $package->invoicesOf($quarter));

        return $this->render('accounting/accountant_export.html.twig', [
            'quarter' => $quarter,
            'rows' => $rows,
            'names' => AccountantPackage::fileNames(array_column($rows, 'invoice')),
            'total' => array_sum(array_map(static fn (array $r): float => (float) $r['invoice']->getTotal(), $rows)),
            'withWarnings' => \count(array_filter($rows, static fn (array $r): bool => $r['warnings'] !== [])),
            'open' => \count($invoices->findOpen()),
            'years' => range((int) date('Y'), (int) date('Y') - 3),
        ]);
    }

    /**
     * El ZIP del trimestre: el libro de facturas recibidas y los documentos
     * renombrados. Sale en flujo: no se monta entero ni en memoria ni en disco.
     */
    #[Route('/download', name: 'accounting_accountant_export_download', methods: ['GET'])]
    public function download(Request $request, AccountantPackage $package): Response
    {
        $quarter = $this->quarterFrom($request);

        $response = new StreamedResponse(static function () use ($package, $quarter): void {
            $output = fopen('php://output', 'wb');
            $package->writeZip($quarter, $output);
            fclose($output);
        });
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('Gestoría facturas recibidas %s.zip', $quarter->label()),
            sprintf('Gestoria facturas recibidas %s.zip', $quarter->label()),
        ));

        return $response;
    }

    /**
     * El trimestre de la petición (`year`, `quarter`), o el último cerrado si no
     * viene o no existe.
     */
    private function quarterFrom(Request $request): InvoiceQuarter
    {
        $default = InvoiceQuarter::lastClosed(new \DateTimeImmutable());
        $year = $request->query->getInt('year', $default->year);
        $quarter = $request->query->getInt('quarter', $default->quarter);

        return $quarter >= 1 && $quarter <= 4 && $year >= 2000 && $year <= 2100
            ? InvoiceQuarter::of($year, $quarter)
            : $default;
    }
}
