<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * La pantalla de alta de una tarea de voluntariado: lo que el navegador tiene
 * que saber ANTES de enviar.
 *
 *  - Que el formulario lleva el validador propio: sin él, a quien tiene el
 *    navegador en inglés le sale «Please fill in this field.» sobre un
 *    formulario en castellano. Pasó.
 *  - Que lo que el servidor exige lo pide también la pantalla (`required`):
 *    antes «desde el» y la hora de inicio sólo fallaban después de enviar.
 *  - Que los campos que dependen de otro llevan la regla que los esconde, y
 *    con el `name` COMPLETO del control del que dependen: la macro lo traduce
 *    del nombre corto, y si se equivocara, el campo se enseñaría siempre sin
 *    que ningún test de formulario lo notase.
 */
class VolunteeringFormScreenTest extends AbstractAuthenticatedTest
{
    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testElFormularioLlevaElValidadorPropio(): void
    {
        $crawler = $this->screen();

        $this->assertCount(1, $crawler->filter('form[name="volunteer_offer"][data-csa-validate]'));
    }

    public function testLoQueExigeElServidorLoPideLaPantalla(): void
    {
        $crawler = $this->screen();

        foreach (['title', 'repeatFrom', 'firstStart'] as $field) {
            $this->assertCount(
                1,
                $crawler->filter(sprintf('[name="volunteer_offer[%s]"][required]', $field)),
                sprintf('«%s» tendría que ser obligatorio también para el navegador.', $field)
            );
        }
    }

    public function testLosCamposQueDependenDeOtroLlevanSuRegla(): void
    {
        $crawler = $this->screen();

        $rules = [
            'repeatEvery' => 'volunteer_offer[repeatType]=weekly',
            'repeatWeekdays' => 'volunteer_offer[repeatType]=weekly|monthly',
            'openEnded' => 'volunteer_offer[repeatType]=weekly|monthly|delivery',
            // Dos reglas: se repite Y no está marcada como sin fin.
            'repeatUntil' => 'volunteer_offer[repeatType]=weekly|monthly|delivery;volunteer_offer[openEnded]=unchecked',
            'place' => 'volunteer_offer[remote]=unchecked',
            'placeNote' => 'volunteer_offer[remote]=unchecked',
            'node' => 'volunteer_offer[remote]=unchecked',
        ];

        foreach ($rules as $field => $rule) {
            $wrapper = $crawler->filter(sprintf('[data-csa-show-when="%s"]', $rule));
            $this->assertGreaterThan(0, $wrapper->count(), sprintf('Falta la regla «%s».', $rule));

            $selector = sprintf('[name="volunteer_offer[%s]"], [name="volunteer_offer[%s][]"]', $field, $field);
            $this->assertGreaterThan(
                0,
                $wrapper->filter($selector)->count(),
                sprintf('«%s» tendría que estar dentro del envoltorio con la regla «%s».', $field, $rule)
            );
        }
    }

    /**
     * La cadencia se ofrece con nombre, no como un número: «2» no le dice
     * «quincenal» a nadie.
     */
    public function testLaCadenciaSeOfreceConNombre(): void
    {
        $crawler = $this->screen();

        $options = $crawler->filter('select[name="volunteer_offer[repeatEvery]"] option')->each(
            static fn (Crawler $option): string => trim($option->text())
        );

        $this->assertSame(['Todas las semanas', 'Una de cada dos (quincenal)', 'Una de cada tres', 'Una de cada cuatro'], $options);
    }

    private function screen(): Crawler
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_VOLUNTEERING, true);

        $crawler = $client->request('GET', '/gestion/voluntariado/nueva');
        $this->assertResponseIsSuccessful();

        return $crawler;
    }
}
