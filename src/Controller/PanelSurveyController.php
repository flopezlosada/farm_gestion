<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\Question;
use App\Entity\Survey;
use App\Entity\SurveyAnswer;
use App\Entity\SurveyParticipation;
use App\Form\SurveyResponseType;
use App\Repository\SurveyParticipationRepository;
use App\Repository\SurveyRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Encuestas desde el lado del socix: listar las abiertas y responderlas.
 *
 * Anonimato "de confianza" (nivel A): al guardar se persisten las
 * {@see SurveyAnswer} (sin referencia al socix) y, por separado, una
 * {@see SurveyParticipation} (que sabe quién participó pero no qué respondió).
 * No hay forma de cruzar respuesta ↔ autor.
 *
 * El anti-duplicado es el UNIQUE(survey, partner) de survey_participation: si
 * el socix intenta responder dos veces, la segunda revienta con violación de
 * unicidad y se le avisa. El {@see SurveyParticipationRepository::hasParticipated}
 * es solo para esconder el formulario en la UI; la garantía dura es el índice.
 */
#[Route('/panel/surveys')]
#[IsGranted('FEATURE_SURVEYS')]
#[IsGranted('ROLE_PARTNER')]
class PanelSurveyController extends AbstractController
{
    /**
     * Listado de encuestas abiertas, marcando las que el socix ya respondió.
     */
    #[Route('', name: 'panel_survey_index', methods: ['GET'])]
    public function index(SurveyRepository $surveys, SurveyParticipationRepository $participations): Response
    {
        $partner = $this->requirePartner();
        if (!$partner instanceof Partner) {
            return $partner;
        }

        $open = $surveys->findOpen();

        $answered = [];
        foreach ($open as $survey) {
            $answered[$survey->getId()] = $participations->hasParticipated($survey, $partner);
        }

        return $this->render('panel_survey/index.html.twig', [
            'surveys'  => $open,
            'answered' => $answered,
        ]);
    }

    /**
     * Formulario para responder una encuesta. Solo si está abierta y el socix
     * no ha respondido todavía.
     */
    #[Route('/{id}', name: 'panel_survey_respond', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function respond(
        Request $request,
        Survey $survey,
        SurveyParticipationRepository $participations,
        EntityManagerInterface $em,
    ): Response {
        $partner = $this->requirePartner();
        if (!$partner instanceof Partner) {
            return $partner;
        }

        if (!$survey->isOpen()) {
            $this->addFlash('warning', 'Esta encuesta no está abierta a respuestas.');

            return $this->redirectToRoute('panel_survey_index');
        }

        if ($participations->hasParticipated($survey, $partner)) {
            $this->addFlash('notice', 'Ya respondiste a esta encuesta. ¡Gracias!');

            return $this->redirectToRoute('panel_survey_index');
        }

        $form = $this->createForm(SurveyResponseType::class, null, ['survey' => $survey]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->persistResponse($survey, $partner, $form->getData(), $em);
            } catch (UniqueConstraintViolationException) {
                // Doble envío / carrera: el UNIQUE(survey, partner) lo corta en seco.
                $this->addFlash('notice', 'Ya habías respondido a esta encuesta. ¡Gracias!');

                return $this->redirectToRoute('panel_survey_index');
            }

            $this->addFlash('notice', '¡Gracias! Hemos guardado tu respuesta de forma anónima.');

            return $this->redirectToRoute('panel_survey_index');
        }

        return $this->render('panel_survey/respond.html.twig', [
            'survey' => $survey,
            'form'   => $form->createView(),
        ]);
    }

    /**
     * Persiste las respuestas (anónimas) y la participación (con identidad) en
     * una sola transacción. Si falla la unicidad, no queda nada a medias.
     *
     * @param array<string, mixed> $data Datos del {@see SurveyResponseType}: fieldName => valor.
     */
    private function persistResponse(
        Survey $survey,
        Partner $partner,
        array $data,
        EntityManagerInterface $em,
    ): void {
        $em->wrapInTransaction(function () use ($survey, $partner, $data, $em): void {
            foreach ($survey->getQuestions() as $question) {
                $value = $data[SurveyResponseType::fieldName($question)] ?? null;

                foreach ($this->answersFor($question, $value) as $answer) {
                    $em->persist($answer);
                }
            }

            $participation = (new SurveyParticipation())
                ->setSurvey($survey)
                ->setPartner($partner);
            $em->persist($participation);
        });
    }

    /**
     * Traduce el valor enviado para una pregunta a sus {@see SurveyAnswer}.
     * Una respuesta vacía (no obligatoria sin contestar) no genera filas.
     *
     * @param mixed $value Id de opción (single), array de ids (multiple),
     *                     entero 1-5 (scale) o string (text).
     * @return SurveyAnswer[]
     */
    private function answersFor(Question $question, mixed $value): array
    {
        return match ($question->getType()) {
            Question::TYPE_SINGLE => $value !== null && $value !== ''
                ? [$this->optionAnswer($question, (int) $value)]
                : [],
            Question::TYPE_MULTIPLE => array_map(
                fn (int $optionId): SurveyAnswer => $this->optionAnswer($question, $optionId),
                array_map('intval', is_array($value) ? $value : [])
            ),
            Question::TYPE_SCALE => $value !== null && $value !== ''
                ? [(new SurveyAnswer())->setQuestion($question)->setValueInt((int) $value)]
                : [],
            Question::TYPE_TEXT => is_string($value) && trim($value) !== ''
                ? [(new SurveyAnswer())->setQuestion($question)->setValueText(trim($value))]
                : [],
            default => [],
        };
    }

    /**
     * Crea una respuesta apuntando a una opción concreta de la pregunta. La
     * opción se resuelve desde la colección ya cargada de la pregunta (las
     * choices del form salieron de ahí), sin volver a BBDD.
     */
    private function optionAnswer(Question $question, int $optionId): SurveyAnswer
    {
        $option = null;
        foreach ($question->getOptions() as $candidate) {
            if ($candidate->getId() === $optionId) {
                $option = $candidate;
                break;
            }
        }

        if ($option === null) {
            // El value vino de las opciones del propio form: no debería pasar.
            throw new \RuntimeException('Opción de respuesta no válida.');
        }

        return (new SurveyAnswer())
            ->setQuestion($question)
            ->setOption($option);
    }

    /**
     * Resuelve el Partner de la sesión. Si el User no está vinculado a un socix,
     * no puede responder: lo saca con un aviso. Devuelve el Partner o un
     * RedirectResponse al panel.
     */
    private function requirePartner(): Partner|RedirectResponse
    {
        $partner = $this->getUser()?->getPartner();
        if ($partner instanceof Partner) {
            return $partner;
        }

        $this->addFlash('error', 'Tu usuaria no está vinculada a un socix; pide a administración que te vincule.');

        return $this->redirectToRoute('panel');
    }
}
