<?php

namespace App\Tests\Controller;

use App\Entity\Obligation;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * El ciclo completo del registro de vencimientos por HTTP: que el módulo está
 * detrás de su flag, que dar de alta deja la fecha vigilada, y que anotar una
 * renovación mueve el vencimiento sin perder el historial.
 *
 * Lo último es lo que hay que proteger de verdad: si una renovación
 * sobrescribiera el periodo anterior en vez de añadir uno, nadie se daría
 * cuenta hasta que hiciera falta saber desde cuándo no se renueva algo.
 */
class ObligationControllerTest extends AbstractAuthenticatedTest
{
    /**
     * Deja la configuración como estaba: los demás tests cuentan con los
     * defaults del catálogo, y este módulo nace apagado.
     */
    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testConElFlagApagadoLaSeccionNoExiste(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/vencimientos');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testConElFlagEncendidoElListadoAbre(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $client->request('GET', '/gestion/vencimientos');

        $this->assertResponseIsSuccessful();
    }

    /**
     * El alta pide la fecha y con eso la ficha queda vigilada desde el primer
     * momento. Una obligación sin fecha no vigila nada, y "ya la pondré" es no
     * darla de alta.
     */
    public function testElAltaDejaLaFechaYaVigilada(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $crawler = $client->request('GET', '/gestion/vencimientos/nueva');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form([
            'obligation[name]' => 'Convenio de prueba de La Cerrada',
            'obligation[kind]' => Obligation::KIND_AGREEMENT,
            'obligation[counterparty]' => 'Ayuntamiento de prueba',
            'obligation[firstEndsOn]' => '2099-04-30',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects();

        $obligation = $this->findByName('Convenio de prueba de La Cerrada');
        $this->assertNotNull($obligation, 'El alta no creó la obligación.');
        $this->assertCount(1, $obligation->getTerms(), 'El alta no creó el primer periodo.');
        $this->assertSame('2099-04-30', $obligation->expiresOn()->format('Y-m-d'));
    }

    /**
     * Sin fecha de caducidad el alta no pasa: es el único campo que hace que la
     * ficha sirva para algo.
     */
    public function testElAltaSinFechaDeCaducidadNoPasa(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $crawler = $client->request('GET', '/gestion/vencimientos/nueva');
        $form = $crawler->filter('form')->form([
            'obligation[name]' => 'Ficha sin fecha',
            'obligation[kind]' => Obligation::KIND_OTHER,
            'obligation[firstEndsOn]' => '',
        ]);
        $client->submit($form);

        $this->assertResponseIsSuccessful(); // vuelve al formulario con el error
        $this->assertNull($this->findByName('Ficha sin fecha'));
    }

    /**
     * El corazón del modelo: renovar AÑADE un periodo. El vencimiento pasa a
     * ser el nuevo y el anterior sigue ahí, que es lo que contesta "¿cada
     * cuánto se renueva esto de verdad?".
     */
    public function testAnotarUnaRenovacionMueveLaFechaYConservaElHistorial(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $crawler = $client->request('GET', '/gestion/vencimientos/nueva');
        $client->submit($crawler->filter('form')->form([
            'obligation[name]' => 'Póliza de prueba',
            'obligation[kind]' => Obligation::KIND_INSURANCE,
            'obligation[firstEndsOn]' => '2099-01-31',
        ]));

        $obligation = $this->findByName('Póliza de prueba');
        $id = $obligation->getId();

        $crawler = $client->request('GET', sprintf('/gestion/vencimientos/%d', $id));
        $this->assertResponseIsSuccessful();

        $client->submit($crawler->filter('form[action*="renovar"]')->form([
            'obligation_term[endsOn]' => '2100-01-31',
        ]));
        $this->assertResponseRedirects();

        // Releído por id: cada petición reinicia el kernel y la instancia
        // anterior queda desligada del gestor de entidades.
        $renewed = $this->reload($id);

        $this->assertSame('2100-01-31', $renewed->expiresOn()->format('Y-m-d'), 'La renovación no movió el vencimiento.');
        $this->assertCount(2, $renewed->getTerms(), 'La renovación pisó el periodo anterior en vez de añadir uno.');
    }

    /**
     * El papel se sube al dar de alta y se abre desde la ficha. Va por un
     * controlador y no por una URL de Apache porque los convenios llevan DNIs
     * e IBAN: fuera de `public/`, quien no tenga el permiso no los ve.
     */
    public function testElDocumentoSubidoEnElAltaSePuedeAbrirDespues(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $crawler = $client->request('GET', '/gestion/vencimientos/nueva');
        $form = $crawler->filter('form')->form([
            'obligation[name]' => 'Convenio con documento',
            'obligation[kind]' => Obligation::KIND_AGREEMENT,
            'obligation[firstEndsOn]' => '2099-06-30',
        ]);
        $form['obligation[document]']->upload($this->samplePdf());
        $client->submit($form);
        $this->assertResponseRedirects();

        $obligation = $this->findByName('Convenio con documento');
        $this->assertNotNull($obligation->getDocumentFile(), 'El alta no archivó el documento.');

        $client->request('GET', sprintf('/gestion/vencimientos/%d/documento', $obligation->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'inline',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
            'Un PDF debería abrirse en el navegador, no descargarse.'
        );
    }

    /**
     * El escaneado casi nunca está el día que se anota la fecha, así que cada
     * periodo del historial admite que se le suba el papel después. Sin esto,
     * archivarlo obligaría a borrar el periodo y rehacerlo.
     */
    public function testSeLePuedeSubirElPapelAUnPeriodoYaAnotado(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $crawler = $client->request('GET', '/gestion/vencimientos/nueva');
        $client->submit($crawler->filter('form')->form([
            'obligation[name]' => 'Ficha con historial',
            'obligation[kind]' => Obligation::KIND_OTHER,
            'obligation[firstEndsOn]' => '2099-09-30',
        ]));

        $obligation = $this->findByName('Ficha con historial');
        $id = $obligation->getId();
        $termId = $obligation->getTerms()->first()->getId();

        $crawler = $client->request('GET', sprintf('/gestion/vencimientos/%d', $id));
        $token = $crawler->filter(sprintf('form[action$="/periodo/%d/documento"] input[name="_token"]', $termId))->attr('value');

        $client->request(
            'POST',
            sprintf('/gestion/vencimientos/%d/periodo/%d/documento', $id, $termId),
            ['_token' => $token],
            ['document' => new UploadedFile($this->samplePdf(), 'convenio-firmado.pdf', 'application/pdf', null, true)]
        );
        $this->assertResponseRedirects();

        $term = $this->reload($id)->getTerms()->first();
        $this->assertNotNull($term->getDocumentFile(), 'El periodo se quedó sin su documento.');

        $client->request('GET', sprintf('/gestion/vencimientos/%d/periodo/%d/documento', $id, $termId));
        $this->assertResponseIsSuccessful();
    }

    /**
     * La fila puede apuntar a un fichero que ya no está —la base se restaura de
     * un volcado y los documentos no viajan con ella—. Eso es un 404, no un
     * error de la aplicación.
     */
    public function testUnDocumentoQueNoEstaEnElArchivoDaUn404(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $crawler = $client->request('GET', '/gestion/vencimientos/nueva');
        $client->submit($crawler->filter('form')->form([
            'obligation[name]' => 'Ficha con documento perdido',
            'obligation[kind]' => Obligation::KIND_OTHER,
            'obligation[firstEndsOn]' => '2099-12-31',
        ]));

        $id = $this->findByName('Ficha con documento perdido')->getId();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(Obligation::class)->find($id)->setDocumentFile('convenio-fantasma-aabbccdd.pdf');
        $em->flush();

        $client->request('GET', sprintf('/gestion/vencimientos/%d/documento', $id));

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * Un PDF de verdad en un fichero temporal: el formato se valida por
     * contenido, así que un fichero de texto con extensión .pdf no colaría.
     *
     * @return string Ruta del fichero recién creado.
     */
    private function samplePdf(): string
    {
        $path = sys_get_temp_dir() . '/obligation-test-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return $path;
    }

    /**
     * Enciende el módulo para la petición en curso.
     */
    private function enableModule(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_VENCIMIENTOS, true);
    }

    /**
     * Busca una obligación por su nombre, releyendo del gestor actual.
     *
     * @param string $name Nombre exacto.
     */
    private function findByName(string $name): ?Obligation
    {
        return static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(Obligation::class)
            ->findOneBy(['name' => $name]);
    }

    /**
     * Relee una obligación por id del gestor actual.
     *
     * @param int $id Identificador.
     */
    private function reload(int $id): Obligation
    {
        return static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(Obligation::class)
            ->find($id);
    }
}
