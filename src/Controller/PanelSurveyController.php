<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\Survey;
use App\Form\SurveyResponseType;
use App\Repository\SurveyParticipationRepository;
use App\Repository\SurveyRepository;
use App\Service\Survey\SurveyResponseRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Encuestas desde el panel de la socia: listar las que admiten respuesta y
 * responderlas. La otra puerta, sin sesión, es el enlace firmado del correo
 * ({@see PublicSurveyController}); las dos guardan con
 * {@see SurveyResponseRecorder}, que es donde vive el anonimato.
 *
 * {@see SurveyParticipationRepository::hasParticipated()} es sólo para no
 * volver a ofrecer el formulario; la garantía dura contra duplicados es el
 * índice único de la base.
 */
#[Route('/panel/surveys')]
#[IsGranted('FEATURE_SURVEYS')]
#[IsGranted('ROLE_PARTNER')]
class PanelSurveyController extends AbstractController
{
    /**
     * Encuestas que admiten respuesta, marcando las que la socia ya respondió.
     */
    #[Route('', name: 'panel_survey_index', methods: ['GET'])]
    public function index(SurveyRepository $surveys, SurveyParticipationRepository $participations): Response
    {
        $partner = $this->requirePartner();
        if (!$partner instanceof Partner) {
            return $partner;
        }

        $open = $surveys->findAcceptingResponses(new \DateTimeImmutable());

        return $this->render('panel_survey/index.html.twig', [
            'surveys'  => $open,
            'answered' => $participations->answeredAmong($open, $partner),
        ]);
    }

    /**
     * Formulario para responder una encuesta. Sólo si admite respuestas y la
     * socia no ha respondido todavía.
     */
    #[Route('/{id}', name: 'panel_survey_respond', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function respond(
        Request $request,
        Survey $survey,
        SurveyParticipationRepository $participations,
        SurveyResponseRecorder $recorder,
    ): Response {
        $partner = $this->requirePartner();
        if (!$partner instanceof Partner) {
            return $partner;
        }

        if (!$survey->acceptsResponses(new \DateTimeImmutable())) {
            $this->addFlash('warning', 'Esta encuesta ya no admite respuestas.');

            return $this->redirectToRoute('panel_survey_index');
        }

        if ($participations->hasParticipated($survey, $partner)) {
            $this->addFlash('notice', 'Ya respondiste a esta encuesta. ¡Gracias!');

            return $this->redirectToRoute('panel_survey_index');
        }

        $form = $this->createForm(SurveyResponseType::class, null, ['survey' => $survey]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$recorder->record($survey, $partner, $form->getData())) {
                $this->addFlash('notice', 'Ya habías respondido a esta encuesta. ¡Gracias!');

                return $this->redirectToRoute('panel_survey_index');
            }

            return $this->redirectToRoute('panel_survey_thanks', ['id' => $survey->getId()]);
        }

        return $this->render('panel_survey/respond.html.twig', [
            'survey' => $survey,
            'form'   => $form->createView(),
        ]);
    }

    /**
     * Pantalla de agradecimiento tras responder. Es una pantalla y no un flash
     * sobre el listado porque cierra la tarea: la socia necesita ver que su
     * respuesta ha llegado y que es anónima, no un aviso que se pierde encima de
     * otra página.
     *
     * Sólo para quien ya participó: sin participación no hay nada que agradecer
     * y se la manda al formulario.
     */
    #[Route('/{id}/thanks', name: 'panel_survey_thanks', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function thanks(Survey $survey, SurveyParticipationRepository $participations): Response
    {
        $partner = $this->requirePartner();
        if (!$partner instanceof Partner) {
            return $partner;
        }

        if (!$participations->hasParticipated($survey, $partner)) {
            return $this->redirectToRoute('panel_survey_respond', ['id' => $survey->getId()]);
        }

        return $this->render('panel_survey/thanks.html.twig', [
            'survey' => $survey,
        ]);
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
