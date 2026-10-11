<?php

namespace App\Tests\Controller;

use App\Entity\ReceivedInvoice;
use App\Entity\TelegramSubmission;
use App\Entity\User;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\AppSettings;
use App\Service\Telegram\TelegramFileStore;
use App\Service\Telegram\TelegramInput;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La bandeja web de envíos al bot: sólo administración, y encaminar o descartar
 * desde aquí hace lo mismo que el botón en Telegram (el fichero va a su apartado,
 * queda apuntado quién lo decidió).
 *
 * En los tests no hay bot (sin token): el aviso a quien lo mandó no sale, y eso no
 * debe impedir encaminar.
 */
class TelegramSubmissionControllerTest extends AbstractAuthenticatedTest
{
    /** @var list<int> Personas creadas por el test. */
    private array $users = [];

    /** Cómo estaba contabilidad antes del test; null si el test no la ha tocado. */
    private ?bool $accountingWasOn = null;

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $invoiceFiles = static::getContainer()->get(InvoiceFileStore::class);
        $telegramFiles = static::getContainer()->get(TelegramFileStore::class);
        foreach ($this->users as $id) {
            $user = $em->find(User::class, $id);
            if ($user === null) {
                continue;
            }
            foreach ($em->getRepository(ReceivedInvoice::class)->findBy(['uploadedBy' => $user]) as $invoice) {
                $invoiceFiles->discard($invoice->getFileName());
                $em->remove($invoice);
            }
            foreach ($em->getRepository(TelegramSubmission::class)->findBy(['user' => $user]) as $submission) {
                $telegramFiles->discard($submission->getFileName());
                $em->remove($submission);
            }
            $em->remove($user);
        }
        $em->flush();
        if ($this->accountingWasOn !== null) {
            static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, $this->accountingWasOn);
        }

        parent::tearDown();
    }

    public function testSinAdministracionNoSeEntra(): void
    {
        $client = static::createClient();
        $client->loginUser($this->person(['ROLE_GESTION_CONTABILIDAD_EDIT']));

        $client->request('GET', '/gestion/telegram/envios');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnPendienteSeVeYSeColocaEnSuApartado(): void
    {
        $client = $this->createAuthenticatedClient();
        $sender = $this->person([]);
        $id = $this->pendingPdf($sender);

        $crawler = $client->request('GET', '/gestion/telegram/envios');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.prl-table', 'Factura Movistar.pdf');

        $form = $crawler->filter(sprintf('button[formaction="/gestion/telegram/envios/%d/route"]', $id))->form(['destination' => 'invoice']);
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/telegram/envios');
        // Sin bot en los tests el aviso no sale, y la web no lo da por hecho.
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'No se ha podido avisar');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $submission = $em->find(TelegramSubmission::class, $id);
        $this->assertSame('invoice', $submission->getDestination());
        $this->assertNotNull($submission->getResolvedBy(), 'queda apuntado quién lo decidió');
        $invoice = $em->find(ReceivedInvoice::class, (int) $submission->getTargetRef());
        $this->assertSame($sender->getId(), $invoice->getUploadedBy()?->getId(), 'la factura es de quien la mandó, no de quien la colocó');
        $this->assertSame(ReceivedInvoice::SOURCE_TELEGRAM, $invoice->getSource());
    }

    public function testDescartarUnPendiente(): void
    {
        $client = $this->createAuthenticatedClient();
        $id = $this->pendingPdf($this->person([]));

        $crawler = $client->request('GET', '/gestion/telegram/envios');
        $client->submit($crawler->filter(sprintf('button[formaction="/gestion/telegram/envios/%d/discard"]', $id))->form());

        $this->assertResponseRedirects('/gestion/telegram/envios');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $this->assertSame(TelegramSubmission::STATUS_DISCARDED, $em->find(TelegramSubmission::class, $id)->getStatus());
    }

    /** Sin el token del formulario no se toca nada. */
    public function testSinTokenNoSeColoca(): void
    {
        $client = $this->createAuthenticatedClient();
        $id = $this->pendingPdf($this->person([]));

        $client->request('POST', sprintf('/gestion/telegram/envios/%d/route', $id), ['destination' => 'invoice', '_token' => 'falso']);

        $this->assertResponseRedirects('/gestion/telegram/envios');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $this->assertTrue($em->find(TelegramSubmission::class, $id)->isPending());
    }

    /**
     * Un PDF que llegó al bot y está pendiente, con su copia guardada.
     *
     * @return int Id del envío.
     */
    private function pendingPdf(User $sender): int
    {
        // Las facturas sólo se admiten con contabilidad encendida. Aquí y no en
        // setUp(): arrancar el kernel antes de createClient() no está permitido.
        $settings = static::getContainer()->get(AppSettings::class);
        $this->accountingWasOn ??= $settings->getBool(AppSettings::FEATURE_CONTABILIDAD);
        $settings->setBool(AppSettings::FEATURE_CONTABILIDAD, true);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $path = tempnam(sys_get_temp_dir(), 'tgs-test-');
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        $submission = new TelegramSubmission($em->find(User::class, $sender->getId()), '555000999', TelegramInput::DOCUMENT, '', new \DateTimeImmutable());
        $submission->attachFile(static::getContainer()->get(TelegramFileStore::class)->keepCopy($path, 'Factura Movistar.pdf'), 'Factura Movistar.pdf', 'application/pdf');
        $submission->askAbout('¿Qué es esto?');
        $em->persist($submission);
        $em->flush();
        @unlink($path);

        return (int) $submission->getId();
    }

    /**
     * @param list<string> $roles
     */
    private function person(array $roles): User
    {
        $user = $this->createUserWithRoles($roles);
        $this->users[] = (int) $user->getId();

        return $user;
    }
}
