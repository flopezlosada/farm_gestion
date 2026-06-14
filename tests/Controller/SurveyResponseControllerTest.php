<?php

namespace App\Tests\Controller;

use App\DataFixtures\PartnerUserFixtures;
use App\Entity\Partner;
use App\Entity\Question;
use App\Entity\Survey;
use App\Entity\User;
use App\Form\SurveyResponseType;
use App\Repository\SurveyAnswerRepository;
use App\Repository\SurveyParticipationRepository;

/**
 * Flujo del socix respondiendo encuestas desde el panel (/panel/surveys):
 * pintado del formulario, guardado anónimo de respuestas + participación, y el
 * anti-duplicado (no se responde dos veces ni a una encuesta no abierta).
 *
 * El gating del feature-flag (403 con el toggle apagado) se cubre en
 * {@see FeatureToggleTest}.
 */
class SurveyResponseControllerTest extends AbstractPartnerAuthenticatedTest
{
    use SurveyTestTrait;

    protected function tearDown(): void
    {
        $this->cleanupSurveys();
        parent::tearDown();
    }

    /**
     * Partner vinculado al User socix de las fixtures.
     */
    private function socixPartner(): Partner
    {
        $user = $this->em()->getRepository(User::class)->loadUserByIdentifier(PartnerUserFixtures::USER_SOCIX_USERNAME);

        return $user->getPartner();
    }

    public function testRespondFormRenders(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_OPEN, 'Para responder');

        $client->request('GET', '/panel/surveys/'.$survey->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testSubmitSavesParticipationAndAnonymousAnswers(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_OPEN, 'A guardar');

        // Pregunta por tipo (orden por posición: 0 scale, 1 single, 2 multiple, 3 text).
        $scale = $survey->getQuestions()->get(0);
        $single = $survey->getQuestions()->get(1);
        $multiple = $survey->getQuestions()->get(2);
        $text = $survey->getQuestions()->get(3);

        $crawler = $client->request('GET', '/panel/surveys/'.$survey->getId());
        $token = $crawler->filter('input[name="survey_response[_token]"]')->attr('value');

        $payload = ['survey_response' => [
            SurveyResponseType::fieldName($scale)    => '4',
            SurveyResponseType::fieldName($single)   => (string) $single->getOptions()->get(2)->getId(),
            SurveyResponseType::fieldName($multiple) => [
                (string) $multiple->getOptions()->get(0)->getId(),
                (string) $multiple->getOptions()->get(2)->getId(),
            ],
            SurveyResponseType::fieldName($text)     => 'Una sugerencia',
            '_token' => $token,
        ]];

        $surveyId = $survey->getId();
        $scaleId = $scale->getId();
        $partnerId = $this->socixPartner()->getId();

        $client->request('POST', '/panel/surveys/'.$surveyId, $payload);
        $this->assertResponseRedirects('/panel/surveys');

        $this->em()->clear();
        $survey = $this->em()->find(Survey::class, $surveyId);
        $partner = $this->em()->find(Partner::class, $partnerId);

        /** @var SurveyParticipationRepository $participations */
        $participations = $this->em()->getRepository(\App\Entity\SurveyParticipation::class);
        $this->assertTrue($participations->hasParticipated($survey, $partner), 'Debe quedar la participación del socix.');

        /** @var SurveyAnswerRepository $answers */
        $answers = $this->em()->getRepository(\App\Entity\SurveyAnswer::class);
        $scale = $this->em()->find(Question::class, $scaleId);
        $this->assertSame([4 => 1], $answers->countByScaleValue($scale), 'La respuesta de escala (4) debe guardarse anónima.');
    }

    public function testCannotRespondTwice(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_OPEN, 'Ya respondida');

        // El socix ya participó: el formulario no se le vuelve a ofrecer.
        $this->addParticipation($survey, $this->socixPartner());

        $client->request('GET', '/panel/surveys/'.$survey->getId());

        $this->assertResponseRedirects('/panel/surveys');
    }

    public function testCannotRespondToClosedSurvey(): void
    {
        $client = $this->createPartnerAuthenticatedClient();
        $this->enableSurveys();
        $survey = $this->makeFullSurvey(Survey::STATUS_CLOSED, 'Cerrada');

        $client->request('GET', '/panel/surveys/'.$survey->getId());

        $this->assertResponseRedirects('/panel/surveys');
    }
}
