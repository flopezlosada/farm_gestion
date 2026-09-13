<?php

namespace App\Tests\Controller;

use App\Entity\Obligation;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

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
