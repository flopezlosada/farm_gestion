<?php

namespace App\Service\Accounting\IssuedInvoice;

use App\Entity\IssuedInvoice;
use App\Service\AppSettings;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * El PDF de una factura emitida, con la misma disposición que las que hacía la
 * asociación a mano: emisor y cliente arriba, la tabla de fecha, concepto e
 * importe, el pie con bases e IVA y el texto de cómo pagar.
 *
 * Un borrador también se puede ver, con «BORRADOR» cruzado: para revisarlo antes
 * de emitirlo. El de una emitida se genera una vez, al emitirla, y se guarda
 * ({@see IssuedInvoiceArchive}).
 */
class IssuedInvoicePdf
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AppSettings $settings,
        #[Autowire('%kernel.project_dir%/src/Resources/public/img/csa_logo_email.png')]
        private readonly string $logoPath,
    ) {
    }

    /** Si están los datos de quien emite: sin ellos una factura no es válida. */
    public function hasIssuer(): bool
    {
        return trim($this->settings->getString(AppSettings::ISSUED_INVOICE_ISSUER)) !== '';
    }

    /** Nombre del fichero al descargarlo: «factura-10-2026.pdf», «factura-borrador-7.pdf». */
    public static function downloadName(IssuedInvoice $invoice): string
    {
        return $invoice->isIssued()
            ? sprintf('factura-%s.pdf', str_replace('/', '-', $invoice->getNumberLabel()))
            : sprintf('factura-borrador-%d.pdf', $invoice->getId());
    }

    /**
     * @return string Bytes del PDF.
     */
    public function render(IssuedInvoice $invoice): string
    {
        $html = $this->twig->render('accounting/issued_invoice_pdf.html.twig', [
            'invoice' => $invoice,
            'issuer' => $this->settings->getString(AppSettings::ISSUED_INVOICE_ISSUER),
            'payment' => $this->settings->getString(AppSettings::ISSUED_INVOICE_PAYMENT),
            'logo' => is_file($this->logoPath) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($this->logoPath)) : null,
        ]);

        // DejaVu Sans: la fuente por defecto de dompdf rompe los acentos y la ñ.
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
