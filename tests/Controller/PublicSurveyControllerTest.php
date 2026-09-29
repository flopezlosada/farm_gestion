<?php

namespace App\Tests\Controller;

use App\Entity\Partner;
use App\Entity\Survey;
use App\Form\SurveyResponseType;
use App\Repository\SurveyParticipationRepository;
use App\Service\Survey\SurveyLink;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Responder desde el enlace del correo, SIN SESIÓN.
 *
 * Es la puerta por la que responderá la mayoría (casi nadie tiene cuenta), y
 * también la única pública del módulo: lo que se protege aquí es que funcione
 * sin login y que la firma no se pueda saltar cambiando un número.
 */
class PublicSurveyControllerTest extends WebTestCase
{
    use SurveyTestTrait;

    protected function tearDown(): void
    {
        $this->cleanupSurveys();
        parent::tearDown();
    }

    public function testElEnlaceFirmadoAbreLaEncuestaSinLogin(): void
    {
        $client = static::createClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_OPEN, 'Por correo');

        $client->request('GET', $this->linkFor($survey, $this->anyPartner()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[data-svy-form]');
        $this->assertSelectorTextContains('h1', 'Por correo');
    }

    /**
     * Cambiar la socia (o la encuesta) de la URL rompe la firma: si no, bastaría
     * con ir probando números para responder en nombre de otras.
     */
    public function testUnEnlaceManipuladoNoSirve(): void
    {
        $client = static::createClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_OPEN, 'Manipulada');
        [$partner, $other] = $this->twoPartners();

        $tampered = str_replace(
            '/respond/' . $partner->getId(),
            '/respond/' . $other->getId(),
            $this->linkFor($survey, $partner)
        );
        $client->request('GET', $tampered);

        $this->assertResponseStatusCodeSame(404);
        $this->assertSelectorNotExists('form[data-svy-form]');
    }

    /**
     * Guarda, vuelve a la misma URL y esa URL ya enseña las gracias: abrir el
     * correo otra vez días después no ofrece el formulario de nuevo.
     */
    public function testResponderGuardaYLaMismaUrlDaLasGracias(): void
    {
        $client = static::createClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_OPEN, 'A responder por correo');
        $partner = $this->anyPartner();
        $url = $this->linkFor($survey, $partner);

        $scale = $survey->getQuestions()->get(0);
        $single = $survey->getQuestions()->get(1);
        $surveyId = $survey->getId();
        $partnerId = $partner->getId();

        $crawler = $client->request('GET', $url);
        $token = $crawler->filter('input[name="survey_response[_token]"]')->attr('value');

        $client->request('POST', $url, ['survey_response' => [
            SurveyResponseType::fieldName($scale)  => '5',
            SurveyResponseType::fieldName($single) => (string) $single->getOptions()->get(0)->getId(),
            '_token' => $token,
        ]]);

        $this->assertResponseRedirects($url);
        $client->followRedirect();
        $this->assertSelectorTextContains('h1', 'Gracias');

        $this->em()->clear();
        /** @var SurveyParticipationRepository $participations */
        $participations = $this->em()->getRepository(\App\Entity\SurveyParticipation::class);
        $this->assertTrue($participations->hasParticipated(
            $this->em()->find(Survey::class, $surveyId),
            $this->em()->find(Partner::class, $partnerId),
        ));
    }

    public function testUnaEncuestaCerradaLoDiceSinFormulario(): void
    {
        $client = static::createClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_CLOSED, 'Ya cerrada');

        $client->request('GET', $this->linkFor($survey, $this->anyPartner()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'cerrada');
        $this->assertSelectorNotExists('form[data-svy-form]');
    }

    /**
     * El enlace firmado, generado igual que en el correo.
     */
    private function linkFor(Survey $survey, Partner $partner): string
    {
        return static::getContainer()->get(SurveyLink::class)->forPartner($survey, $partner);
    }

    /**
     * Una socia cualquiera de db_test.
     */
    private function anyPartner(): Partner
    {
        return $this->twoPartners()[0];
    }

    /**
     * Dos socias distintas de db_test.
     *
     * @return array{0: Partner, 1: Partner}
     */
    private function twoPartners(): array
    {
        $partners = $this->em()->getRepository(Partner::class)->findBy([], ['id' => 'ASC'], 2);
        if (\count($partners) < 2) {
            self::markTestSkipped('db_test necesita al menos dos socias (fixtures).');
        }

        return [$partners[0], $partners[1]];
    }
}
