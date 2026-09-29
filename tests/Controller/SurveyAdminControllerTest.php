<?php

namespace App\Tests\Controller;

use App\Entity\Question;
use App\Entity\Survey;
use App\Entity\User;

/**
 * Flujo de gestión de encuestas (ROLE_GESTION_ENCUESTAS, vía admin): alta,
 * ciclo de vida draft→open→closed, reglas de borrado/archivado y resultados.
 *
 * El gating del feature-flag (403 con el toggle apagado) se cubre aparte en
 * {@see FeatureToggleTest}; aquí el toggle se enciende para ejercitar el flujo.
 */
class SurveyAdminControllerTest extends AbstractAuthenticatedTest
{
    use SurveyTestTrait;

    protected function tearDown(): void
    {
        $this->cleanupSurveys();
        parent::tearDown();
    }

    public function testCreateSurveyPersistsDraftConPreguntaYOpciones(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();

        $crawler = $client->request('GET', '/gestion/surveys/new');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="survey[_token]"]')->attr('value');

        $client->request('POST', '/gestion/surveys/new', ['survey' => [
            'title' => 'Creada en test',
            'description' => '',
            'questions' => [
                ['text' => '¿Vienes?', 'type' => Question::TYPE_SINGLE, 'required' => '1', 'options' => [
                    ['label' => 'Sí'],
                    ['label' => 'No'],
                ]],
            ],
            '_token' => $token,
        ]]);

        $this->assertResponseRedirects('/gestion/surveys/');

        $this->em()->clear();
        $survey = $this->em()->getRepository(Survey::class)->findOneBy(['title' => 'Creada en test']);
        $this->assertNotNull($survey, 'La encuesta debería haberse creado.');
        $this->createdSurveyIds[] = $survey->getId();

        $this->assertSame(Survey::STATUS_DRAFT, $survey->getStatus());
        $this->assertCount(1, $survey->getQuestions());
        $this->assertCount(2, $survey->getQuestions()->first()->getOptions());
    }

    public function testOpenRequiresQuestions(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeEmptySurvey(Survey::STATUS_DRAFT, 'Vacía sin preguntas');

        // Una encuesta vacía no puede abrirse: el botón "Abrir" del listado sí
        // existe (es borrador), pero el controller lo rechaza y la deja en borrador.
        $crawler = $client->request('GET', '/gestion/surveys/');
        $form = $crawler->filter('form[action="/gestion/surveys/'.$survey->getId().'/open"]')->form();
        $client->submit($form);

        $this->em()->clear();
        $reloaded = $this->em()->find(Survey::class, $survey->getId());
        $this->assertSame(Survey::STATUS_DRAFT, $reloaded->getStatus(), 'No debe abrirse sin preguntas.');
    }

    /**
     * Una pregunta de opción única sin opciones sale en la encuesta sin nada que
     * marcar y, siendo obligatoria, nadie puede enviarla. Se para al guardar.
     */
    public function testCreateRejectsChoiceQuestionWithoutOptions(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();

        $crawler = $client->request('GET', '/gestion/surveys/new');
        $token = $crawler->filter('input[name="survey[_token]"]')->attr('value');

        $crawler = $client->request('POST', '/gestion/surveys/new', ['survey' => [
            'title' => 'Sin opciones en test',
            'questions' => [
                ['text' => '¿Vienes?', 'type' => Question::TYPE_SINGLE, 'required' => '1'],
            ],
            '_token' => $token,
        ]]);

        // Vuelve a pintar el formulario (200: el controller pasa la vista, no el
        // form, así que Symfony no pone el 422) con el error junto a la pregunta.
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Añade al menos 2 opciones', $crawler->text());
        $this->assertNull(
            $this->em()->getRepository(Survey::class)->findOneBy(['title' => 'Sin opciones en test']),
            'No debe guardarse.'
        );
    }

    /**
     * Un borrador antiguo guardado antes de esa validación tampoco se abre: la
     * comprobación se repite al abrir, que es cuando se avisa a todo el mundo.
     */
    public function testOpenRefusesChoiceQuestionWithoutOptions(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeDraftWithEmptyChoice();

        $crawler = $client->request('GET', '/gestion/surveys/');
        $client->submit($crawler->filter('form[action="/gestion/surveys/'.$survey->getId().'/open"]')->form());

        $this->em()->clear();
        $reloaded = $this->em()->find(Survey::class, $survey->getId());
        $this->assertSame(Survey::STATUS_DRAFT, $reloaded->getStatus());
        $this->assertNull($reloaded->getAnnouncedAt(), 'Sin abrir no se avisa a nadie.');
    }

    public function testOpenAnnouncesToTheAssociation(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_DRAFT, 'Se abre y se avisa');

        $crawler = $client->request('GET', '/gestion/surveys/');
        $client->submit($crawler->filter('form[action="/gestion/surveys/'.$survey->getId().'/open"]')->form());
        $this->assertResponseRedirects('/gestion/surveys/');

        $this->em()->clear();
        $reloaded = $this->em()->find(Survey::class, $survey->getId());
        $this->assertSame(Survey::STATUS_OPEN, $reloaded->getStatus());
        $this->assertNotNull($reloaded->getAnnouncedAt(), 'Abrir tiene que dejar constancia del aviso.');
    }

    /**
     * Desde la ficha se reenvía el enlace a UNA socia; el aviso general de una
     * encuesta ya avisada no se ofrece.
     */
    public function testResendFromShowGoesToOnePartner(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_OPEN, 'Para reenviar');
        $survey->setAnnouncedAt(new \DateTime('-1 day'));
        $this->em()->flush();

        $listing = $client->request('GET', '/gestion/surveys/');
        $this->assertCount(0, $listing->filter('form[action="/gestion/surveys/'.$survey->getId().'/announce"]'), 'Ya avisada: no se ofrece repetir el aviso a todas.');

        $crawler = $client->request('GET', '/gestion/surveys/'.$survey->getId());
        $form = $crawler->filter('form[action="/gestion/surveys/'.$survey->getId().'/resend"]')->form();
        $partnerId = $form['partner']->availableOptionValues()[1];
        $form['partner']->select($partnerId);
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/surveys/'.$survey->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Enlace reenviado a');
    }

    /**
     * La vista previa es el formulario REAL de la socia (mismas clases que el
     * enlace del correo), pero sin forma de enviarlo.
     */
    public function testPreviewShowsTheRealFormWithoutSubmit(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_DRAFT, 'Para previsualizar');

        $crawler = $client->request('GET', '/gestion/surveys/'.$survey->getId().'/preview');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[data-svy-form][data-svy-preview]');
        $this->assertCount(4, $crawler->filter('fieldset.svy-q'), 'Una por pregunta, como la ve la socia.');
        $this->assertCount(0, $crawler->filter('button[type="submit"]'), 'En la vista previa no se puede enviar.');
    }

    public function testShowOffersOpenForADraft(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_DRAFT, 'Borrador en ficha');

        $crawler = $client->request('GET', '/gestion/surveys/'.$survey->getId());

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('form[action="/gestion/surveys/'.$survey->getId().'/open"]'));
        $this->assertCount(1, $crawler->filter('a[href="/gestion/surveys/'.$survey->getId().'/preview"]'));
    }

    public function testEditRedirectsWhenNotDraft(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_OPEN, 'Abierta no editable');

        $client->request('GET', '/gestion/surveys/'.$survey->getId().'/edit');

        $this->assertResponseRedirects('/gestion/surveys/');
    }

    public function testDeleteAllowedForDraft(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_DRAFT, 'Borrador a borrar');
        $id = $survey->getId();

        $crawler = $client->request('GET', '/gestion/surveys/');
        $form = $crawler->filter('form[action="/gestion/surveys/'.$id.'/delete"]')->form();
        $client->submit($form);

        $this->em()->clear();
        $this->assertNull($this->em()->find(Survey::class, $id), 'Un borrador sí se borra.');
    }

    public function testDeleteBlockedOnceOpened(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_DRAFT, 'Se abre y no se borra');
        $id = $survey->getId();

        // El token de borrado se renderiza mientras es borrador; lo guardamos.
        $crawler = $client->request('GET', '/gestion/surveys/');
        $deleteToken = $crawler->filter('form[action="/gestion/surveys/'.$id.'/delete"] input[name="_token"]')->attr('value');

        // La abrimos (ya no es borrador).
        $client->submit($crawler->filter('form[action="/gestion/surveys/'.$id.'/open"]')->form());

        // Reintentamos borrar con un token válido: el guard de servidor lo corta
        // porque ya no es editable. El token sigue siendo válido (misma sesión).
        $client->request('POST', '/gestion/surveys/'.$id.'/delete', ['_token' => $deleteToken]);

        $this->em()->clear();
        $this->assertNotNull($this->em()->find(Survey::class, $id), 'Una encuesta abierta NO se borra.');
    }

    public function testArchiveHidesFromListingAndUnarchiveRestores(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_OPEN, 'Para archivar zzz');
        $id = $survey->getId();

        // Archivar.
        $crawler = $client->request('GET', '/gestion/surveys/');
        $client->submit($crawler->filter('form[action="/gestion/surveys/'.$id.'/archive"]')->form());

        $this->em()->clear();
        $this->assertTrue($this->em()->find(Survey::class, $id)->isArchived());

        // Ya no sale en el listado activo, sí en el de archivadas.
        $active = $client->request('GET', '/gestion/surveys/');
        $this->assertStringNotContainsString('Para archivar zzz', $active->text());

        $archived = $client->request('GET', '/gestion/surveys/?archived=1');
        $this->assertStringContainsString('Para archivar zzz', $archived->text());

        // Desarchivar la devuelve al listado.
        $client->submit($archived->filter('form[action="/gestion/surveys/'.$id.'/unarchive"]')->form());
        $this->em()->clear();
        $this->assertFalse($this->em()->find(Survey::class, $id)->isArchived());
    }

    public function testResultsShowsAggregatesYResaltaGanadora(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_OPEN, 'Con resultados');

        // La 2ª pregunta es la single (Lunes/Miércoles/Viernes).
        $single = $survey->getQuestions()->get(1);
        $options = $single->getOptions();
        $this->addOptionAnswers($single, $options->get(2), 3); // Viernes ×3
        $this->addOptionAnswers($single, $options->get(0), 1); // Lunes ×1

        // 4 participaciones (denominador) con partners reales del dump de fixtures.
        $partners = $this->em()->getRepository(\App\Entity\Partner::class)->findBy([], null, 4);
        foreach ($partners as $partner) {
            $this->addParticipation($survey, $partner);
        }

        $crawler = $client->request('GET', '/gestion/surveys/'.$survey->getId().'/results');

        $this->assertResponseIsSuccessful();
        $text = $crawler->text();
        $this->assertStringContainsString('Viernes', $text);
        $this->assertStringContainsString('3 · 75%', $text); // 3 de 4 participantes

        // Viernes es la ganadora: su fila lleva el modificador svr-bar--winner.
        $winnerLabels = $crawler->filter('.svr-bar--winner .svr-bar__label')->each(fn ($n) => trim($n->text()));
        $this->assertContains('Viernes', $winnerLabels);
    }

    /**
     * Modelo lectura/escritura aplicado a encuestas: un usuario con SOLO
     * ROLE_GESTION_ENCUESTAS (lectura) ve el listado (200) pero el firewall le
     * rechaza con 403 crear una encuesta (toda escritura es POST). La
     * contraparte de escritura la ejercitan el resto de tests de esta clase
     * vía admin (que tiene _EDIT por jerarquía).
     */
    public function testReadOnlyRoleCannotCreateSurvey(): void
    {
        $client = static::createClient();
        $this->enableSurveys();
        $user = $this->createUserWithRoles(['ROLE_GESTION_ENCUESTAS']);
        $userId = $user->getId();
        $client->loginUser($user);

        $client->request('GET', '/gestion/surveys/');
        $this->assertResponseIsSuccessful();

        $client->request('POST', '/gestion/surveys/new');
        $this->assertResponseStatusCodeSame(403);

        // Tampoco se le enseña el formulario: rellenarlo entero para chocar con
        // un 403 al guardar era la peor forma de enterarse.
        $client->request('GET', '/gestion/surveys/new');
        $this->assertResponseStatusCodeSame(403);

        $em = $this->em();
        $em->remove($em->getRepository(User::class)->find($userId));
        $em->flush();
    }
}
