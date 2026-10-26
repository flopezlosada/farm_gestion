<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Accounting\Invoice\MailedInvoiceIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * Los correos del buzón de facturas, contra la base y el archivo de verdad. Los
 * correos se arman con el mismo MIME que manda un programa de correo: un adjunto
 * lleva su disposición «attachment» y el logotipo de la firma va incrustado con
 * Content-ID, que es justo lo que hay que distinguir.
 */
class MailedInvoiceIntakeTest extends KernelTestCase
{
    /** PDF mínimo: basta la cabecera para que el contenido delate el formato. */
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** PNG de 1×1, como el logotipo de una firma. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private EntityManagerInterface $em;
    private InvoiceFileStore $files;
    private MailedInvoiceIntake $intake;

    /** Última factura que había antes del test: lo posterior es del test. */
    private int $lastId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->files = static::getContainer()->get(InvoiceFileStore::class);
        $this->intake = static::getContainer()->get(MailedInvoiceIntake::class);
        $this->lastId = (int) $this->em->createQuery('SELECT MAX(i.id) FROM ' . ReceivedInvoice::class . ' i')->getSingleScalarResult();
    }

    protected function tearDown(): void
    {
        foreach ($this->taken() as $invoice) {
            $this->files->discard($invoice->getFileName());
            $this->em->remove($invoice);
        }
        $this->em->flush();

        parent::tearDown();
    }

    /** El PDF adjunto queda en cola, con su nombre, su formato y el origen «correo», sin nadie que lo subiera. */
    public function testElPdfAdjuntoQuedaEnColaComoFacturaDelBuzon(): void
    {
        $taken = $this->intake->take($this->mail()->attach(self::PDF, 'Factura F-2026-0412.pdf', 'application/pdf')->toString());

        $this->assertSame(1, $taken);
        [$invoice] = $this->taken();
        $this->assertSame('Factura F-2026-0412.pdf', $invoice->getOriginalName());
        $this->assertSame('application/pdf', $invoice->getMimeType());
        $this->assertSame(ReceivedInvoice::SOURCE_EMAIL, $invoice->getSource());
        $this->assertSame(ReceivedInvoice::STATUS_PENDING, $invoice->getStatus());
        $this->assertNull($invoice->getUploadedBy());
        $this->assertSame(self::PDF, file_get_contents((string) $this->files->pathTo($invoice->getFileName())));
    }

    /** El logotipo de la firma no es una factura; la foto de un ticket adjunta, sí. */
    public function testElLogotipoDeLaFirmaNoCuentaYUnaFotoAdjuntaSi(): void
    {
        $mail = $this->mail()
            ->html('<p>Adjunto el ticket.</p><img src="cid:logo">')
            ->embed(base64_decode(self::PNG), 'logo', 'image/png')
            ->attach(base64_decode(self::PNG), 'ticket.png', 'image/png');

        $this->assertSame(1, $this->intake->take($mail->toString()));
        $this->assertSame(['ticket.png'], array_map(fn (ReceivedInvoice $i) => $i->getOriginalName(), $this->taken()));
    }

    /** Un correo con varias facturas deja una por adjunto. */
    public function testCadaAdjuntoEsUnaFactura(): void
    {
        $mail = $this->mail()
            ->attach(self::PDF, 'factura-septiembre.pdf', 'application/pdf')
            ->attach(self::PDF, 'factura-octubre.pdf', 'application/pdf');

        $this->assertSame(2, $this->intake->take($mail->toString()));
    }

    /**
     * Lo normal es que la factura llegue al correo de la asociación y alguien la
     * reenvíe al buzón. Reenviada «como adjunto», el original viaja como .eml con
     * nombre y su PDF tiene que salir igual.
     */
    public function testUnaFacturaReenviadaComoAdjuntoSaleDelCorreoOriginal(): void
    {
        $original = $this->mail()->attach(self::PDF, 'factura-gasoil.pdf', 'application/pdf')->toString();
        $forward = (new Email())
            ->from('tesoreria@example.org')
            ->to('facturas@example.org')
            ->subject('Fwd: Su factura')
            ->text('Te reenvío la del gasoil.')
            ->attach($original, 'Su factura.eml', 'message/rfc822');

        $this->assertSame(1, $this->intake->take($forward->toString()));
        $this->assertSame(['factura-gasoil.pdf'], array_map(fn (ReceivedInvoice $i) => $i->getOriginalName(), $this->taken()));
    }

    /**
     * Sin adjunto (la factura viene como enlace) o con un adjunto que no se puede
     * guardar como factura, no deja nada: el correo va a revisar a mano.
     */
    public function testUnCorreoSinNadaQueValgaNoDejaNada(): void
    {
        $withLink = $this->mail()->text('Descargue su factura en https://clientes.example.com/facturas/123');
        $withInvite = $this->mail()->attach("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'reunion.ics', 'text/calendar');

        $this->assertSame(0, $this->intake->take($withLink->toString()));
        $this->assertSame(0, $this->intake->take($withInvite->toString()));
        $this->assertSame([], $this->taken());
    }

    /**
     * Correo de un proveedor, con su cuerpo de texto como lo trae cualquiera.
     *
     * @return Email Correo al que añadir los adjuntos.
     */
    private function mail(): Email
    {
        return (new Email())
            ->from('facturacion@proveedor.example.com')
            ->to('facturas@example.org')
            ->subject('Su factura')
            ->text('Le adjuntamos la factura del mes.');
    }

    /**
     * Facturas creadas por el test, en orden.
     *
     * @return list<ReceivedInvoice> Facturas posteriores a las que había.
     */
    private function taken(): array
    {
        return $this->em->createQuery('SELECT i FROM ' . ReceivedInvoice::class . ' i WHERE i.id > :last ORDER BY i.id')
            ->setParameter('last', $this->lastId)
            ->getResult();
    }
}
