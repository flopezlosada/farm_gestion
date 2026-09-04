<?php

namespace App\Tests\Controller;

use App\Entity\Node;
use App\Entity\Setting;
use App\Service\AppSettings;
use App\Service\Volunteering\DeliveryPrepOffers;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El bloque «Montaje de las cestas» en la pantalla del punto de recogida.
 *
 * Lo que se blinda es lo que no ve un test de formulario: que los campos
 * lleguen PINTADOS a la página. Si la plantilla se olvida de uno, `form_rest`
 * lo saca en crudo al final —sin etiqueta, sin diseño y fuera de su sección—,
 * que es exactamente lo que pasó en el alta de tareas de voluntariado.
 *
 * Y que el bloque desaparezca con el módulo apagado: preguntar por el montaje
 * cuando el voluntariado está cerrado ofrecería configurar algo que después no
 * convoca a nadie.
 */
class NodeDeliveryPrepScreenTest extends AbstractAuthenticatedTest
{
    /**
     * Limpia los overrides de configuración: los demás tests cuentan con los
     * valores por defecto del catálogo, y el módulo de voluntariado nace
     * apagado.
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

    /**
     * Con el módulo encendido, los cinco campos salen en la página. Se
     * comprueban por su selector, no por el texto: lo que interesa es que la
     * plantilla los pinte, no cómo se llame la etiqueta.
     */
    public function testConElModuloEncendidoElFormularioEnsenaElBloqueDeMontaje(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $crawler = $client->request('GET', '/gestion/node/1/edit');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Montaje de las cestas', $crawler->filter('body')->text());

        foreach (['deliveryPrep', 'deliveryPrepTime', 'deliveryPrepSlots', 'deliveryPrepMinutes', 'deliveryPrepDayOffset'] as $field) {
            $this->assertCount(
                1,
                $crawler->filter(sprintf('[name="node[%s]"]', $field)),
                sprintf('El campo "%s" debería estar pintado una vez en la pantalla.', $field)
            );
        }
    }

    /**
     * Y ninguno de los cinco sale en crudo al final de la página: todos viven
     * dentro de la sección del montaje, que es lo que `form_rest` se salta.
     */
    public function testLosCamposDelMontajeVivenDentroDeSuSeccion(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $crawler = $client->request('GET', '/gestion/node/1/edit');

        $section = $crawler->filter('.csa-form-section')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Montaje de las cestas')
        );

        $this->assertCount(1, $section, 'Debería haber una sección «Montaje de las cestas».');

        foreach (['deliveryPrep', 'deliveryPrepTime', 'deliveryPrepSlots', 'deliveryPrepMinutes', 'deliveryPrepDayOffset'] as $field) {
            $this->assertCount(
                1,
                $section->filter(sprintf('[name="node[%s]"]', $field)),
                sprintf('El campo "%s" está fuera de su sección: lo habrá sacado form_rest en crudo.', $field)
            );
        }
    }

    /**
     * Con el módulo apagado, que es como está en producción, la pantalla no
     * pregunta por el montaje.
     */
    public function testConElModuloApagadoNoHayBloqueDeMontaje(): void
    {
        $client = $this->createAuthenticatedClient();

        $crawler = $client->request('GET', '/gestion/node/1/edit');

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Montaje de las cestas', $crawler->filter('body')->text());
        $this->assertCount(0, $crawler->filter('[name="node[deliveryPrep]"]'));
    }

    /**
     * Y el alta tampoco: el bloque va en las dos pantallas o en ninguna.
     */
    public function testElAltaTambienEnsenaElBloqueConElModuloEncendido(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $crawler = $client->request('GET', '/gestion/node/new');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[name="node[deliveryPrep]"]'));
    }

    /**
     * El camino entero, que es el que importa: se marca el montaje en la
     * pantalla, se guarda, y el punto queda configurado. Autocontenido: crea su
     * propio punto y lo borra.
     */
    public function testMarcarElMontajeDesdeLaPantallaLoGuarda(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $em = static::getContainer()->get('doctrine')->getManager();
        $node = (new Node())
            ->setName('TEST Nodo montaje ' . uniqid())
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_WEEKLY);
        $em->persist($node);
        $em->flush();
        $nodeId = $node->getId();

        $crawler = $client->request('GET', sprintf('/gestion/node/%d/edit', $nodeId));
        $form = $crawler->filter('form[name="node"]')->form();
        $form['node[deliveryPrep]']->tick();
        $form['node[deliveryPrepDayOffset]'] = '-1';
        $form['node[deliveryPrepTime]'] = '18:30';
        $form['node[deliveryPrepSlots]'] = '4';
        $form['node[deliveryPrepMinutes]'] = '90';
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/node/', message: 'Guardar el montaje debería redirigir al listado.');

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $saved = $em->getRepository(Node::class)->find($nodeId);

        $this->assertTrue($saved->isDeliveryPrep());
        $this->assertSame(-1, $saved->getDeliveryPrepDayOffset());
        $this->assertSame('18:30', $saved->getDeliveryPrepTime()?->format('H:i'));
        $this->assertSame(4, $saved->getDeliveryPrepSlots());
        $this->assertSame(90, $saved->getDeliveryPrepMinutes());

        // Y la cuenta que sale de ahí: el montaje del viernes 4 cae el jueves 3.
        [$start, $end] = $saved->deliveryPrepWindowFor(new \DateTimeImmutable('2026-09-04'));
        $this->assertSame('2026-09-03 18:30', $start->format('Y-m-d H:i'));
        $this->assertSame('2026-09-03 20:00', $end->format('Y-m-d H:i'));

        $em->remove($saved);
        $em->flush();
    }

    /**
     * Marcar el montaje sin decir la hora no se guarda, y la pantalla lo dice.
     */
    public function testMarcarElMontajeSinHoraNoSeGuarda(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $em = static::getContainer()->get('doctrine')->getManager();
        $node = (new Node())
            ->setName('TEST Nodo sin hora ' . uniqid())
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_WEEKLY);
        $em->persist($node);
        $em->flush();
        $nodeId = $node->getId();

        $crawler = $client->request('GET', sprintf('/gestion/node/%d/edit', $nodeId));
        $form = $crawler->filter('form[name="node"]')->form();
        $form['node[deliveryPrep]']->tick();
        $client->submit($form);

        $this->assertSame(
            200,
            $client->getResponse()->getStatusCode(),
            'Un punto sin hora de montaje no debe guardarse: se repinta el formulario.'
        );
        $this->assertStringContainsString(
            'hace falta saber a qué hora',
            (string) $client->getResponse()->getContent()
        );

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $saved = $em->getRepository(Node::class)->find($nodeId);
        $this->assertFalse($saved->isDeliveryPrep(), 'No debe haberse guardado el montaje.');

        $em->remove($saved);
        $em->flush();
    }

    /**
     * La ficha del punto dice cómo monta y enlaza su convocatoria con el estado
     * en que está. Es lo que no se ve desde el formulario: marcar la casilla
     * crea la convocatoria EN BORRADOR, y si la ficha no lo dijera, la casilla
     * parecería no haber hecho nada. Autocontenido: crea su punto y su
     * convocatoria, y los borra.
     */
    public function testLaFichaDelPuntoDiceComoMontaYEnlazaSuConvocatoria(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->settings()->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $node = $this->nodeThatAssembles('TEST Ficha montaje ' . uniqid());
        $em->persist($node);
        $em->flush();
        $nodeId = $node->getId();
        $offer = static::getContainer()->get(DeliveryPrepOffers::class)->sync($node)['offer'];
        $em->flush();

        $crawler = $client->request('GET', sprintf('/gestion/node/%d', $nodeId));

        $this->assertResponseIsSuccessful();
        $text = preg_replace('/\s+/', ' ', $crawler->filter('.nod-head__sub')->text());
        $this->assertStringContainsString('montaje con voluntariado la víspera, 18:30–20:00, 4 personas', $text);
        $this->assertStringContainsString('convocatoria en borrador', $text);

        $link = $crawler->filter('.nod-head__sub a');
        $this->assertCount(1, $link, 'La ficha debería enlazar la convocatoria de montaje una vez.');
        $this->assertSame(sprintf('/gestion/voluntariado/tarea/%d', $offer->getId()), $link->attr('href'));

        $em->remove($offer);
        $em->remove($node);
        $em->flush();
    }

    /**
     * Con el módulo apagado, la ficha no habla del montaje aunque el punto lo
     * tenga configurado: el dato se conserva, pero no se ofrece lo que no va a
     * convocar a nadie.
     */
    public function testConElModuloApagadoLaFichaNoHablaDelMontaje(): void
    {
        $client = $this->createAuthenticatedClient();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $node = $this->nodeThatAssembles('TEST Ficha montaje apagado ' . uniqid());
        $em->persist($node);
        $em->flush();

        $crawler = $client->request('GET', sprintf('/gestion/node/%d', $node->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('montaje con voluntariado', $crawler->filter('body')->text());

        $em->remove($node);
        $em->flush();
    }

    /**
     * Un punto que reparte los viernes y monta la víspera a las 18:30, con 4
     * personas y 90 minutos: la misma receta que Torremocha en las fixtures.
     *
     * @param string $name nombre del punto, único para no chocar con otros tests
     *
     * @return Node el punto, sin persistir
     */
    private function nodeThatAssembles(string $name): Node
    {
        return (new Node())
            ->setName($name)
            ->setDeliveryWeekday(5)
            ->setCadence(Node::CADENCE_WEEKLY)
            ->setDeliveryPrep(true)
            ->setDeliveryPrepDayOffset(-1)
            ->setDeliveryPrepTime(new \DateTime('18:30'))
            ->setDeliveryPrepSlots(4)
            ->setDeliveryPrepMinutes(90);
    }

    private function settings(): AppSettings
    {
        return static::getContainer()->get(AppSettings::class);
    }
}
