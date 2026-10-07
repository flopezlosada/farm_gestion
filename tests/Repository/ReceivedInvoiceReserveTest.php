<?php

namespace App\Tests\Repository;

use App\Entity\ReceivedInvoice;
use App\Repository\ReceivedInvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Una factura se confirma una sola vez aunque lleguen dos confirmaciones a la vez
 * (un doble clic en «Guardar»): la reserva es una sola sentencia condicionada a que
 * siga abierta, y la segunda la encuentra ya confirmada. Sin esto se creaban dos
 * apuntes y el segundo dejaba al primero huérfano.
 */
class ReceivedInvoiceReserveTest extends KernelTestCase
{
    public function testSoloLaPrimeraReservaEsBuena(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var ReceivedInvoiceRepository $repo */
        $repo = $em->getRepository(ReceivedInvoice::class);

        $invoice = new ReceivedInvoice('reserva-prueba.pdf', 'reserva-prueba.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $em->persist($invoice);
        $em->flush();

        try {
            $this->assertTrue($repo->reserveForConfirmation($invoice), 'Abierta, la primera la reserva.');
            $this->assertFalse($repo->reserveForConfirmation($invoice), 'Ya confirmada, la segunda no.');

            $em->clear();
            $this->assertSame(ReceivedInvoice::STATUS_CONFIRMED, $repo->find($invoice->getId())?->getStatus());
        } finally {
            $em->clear();
            $em->remove($em->getReference(ReceivedInvoice::class, $invoice->getId()));
            $em->flush();
        }
    }

    /** Una descartada tampoco se puede confirmar: ya no está en la bandeja. */
    public function testUnaDescartadaNoSeReserva(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        $repo = $em->getRepository(ReceivedInvoice::class);

        $invoice = new ReceivedInvoice('reserva-prueba.pdf', 'reserva-prueba.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $invoice->discard();
        $em->persist($invoice);
        $em->flush();

        try {
            $this->assertFalse($repo->reserveForConfirmation($invoice));
        } finally {
            $em->remove($invoice);
            $em->flush();
        }
    }
}
