<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\Survey;
use App\Form\SurveyResponseType;
use App\Repository\SurveyParticipationRepository;
use App\Service\Survey\SurveyLink;
use App\Service\Survey\SurveyResponseRecorder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Responder una encuesta desde el enlace del correo, SIN SESIÓN.
 *
 * La identidad no sale de un login sino de la firma de la URL
 * ({@see SurveyLink}): lleva la encuesta y la socia, y cualquier cambio la
 * invalida. Guarda con el mismo {@see SurveyResponseRecorder} que el panel.
 *
 * UNA SOLA URL PARA TODO EL RECORRIDO. El formulario se envía a la misma
 * dirección firmada y, al guardar, se redirige a ella; como la socia ya
 * participó, esa misma URL enseña ahora el agradecimiento. Así no hace falta
 * firmar una segunda ruta para las gracias, y volver a abrir el correo días
 * después dice «ya respondiste» en lugar de ofrecer el formulario otra vez.
 *
 * Ruta pública declarada en security.yaml (^/surveys/).
 */
#[IsGranted('FEATURE_SURVEYS')]
class PublicSurveyController extends AbstractController
{
    /**
     * El formulario, el agradecimiento o el aviso de cerrada, según el momento.
     */
    #[Route('/surveys/{id}/respond/{partner}', name: 'survey_public_respond', methods: ['GET', 'POST'], requirements: ['id' => '\d+', 'partner' => '\d+'])]
    public function respond(
        Request $request,
        Survey $survey,
        #[MapEntity(id: 'partner')] Partner $partner,
        SurveyLink $link,
        SurveyParticipationRepository $participations,
        SurveyResponseRecorder $recorder,
    ): Response {
        if (!$link->isValid($request)) {
            // Casi siempre es un enlace cortado por el programa de correo, no
            // alguien probando números. Se le dice qué hacer, con un 404 para
            // no confirmar nada a quien sí esté probando.
            return $this->render('public_survey/message.html.twig', [
                'title' => 'Este enlace no funciona',
                'text'  => 'Puede que se haya cortado al copiarlo. Vuelve al correo y púlsalo directamente, o copia la dirección completa.',
            ], new Response('', Response::HTTP_NOT_FOUND));
        }

        if ($participations->hasParticipated($survey, $partner)) {
            return $this->render('public_survey/thanks.html.twig', ['survey' => $survey]);
        }

        if (!$survey->acceptsResponses(new \DateTimeImmutable())) {
            return $this->render('public_survey/message.html.twig', [
                'title' => 'Esta encuesta ya está cerrada',
                'text'  => sprintf('«%s» ya no admite respuestas. Gracias igualmente por pasarte.', $survey->getTitle()),
            ]);
        }

        $form = $this->createForm(SurveyResponseType::class, null, ['survey' => $survey]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Si ya había respondido (doble envío), el recorder no guarda nada y
            // la misma URL enseña igualmente el agradecimiento.
            $recorder->record($survey, $partner, $form->getData());

            return $this->redirect($request->getUri());
        }

        return $this->render('public_survey/respond.html.twig', [
            'survey'  => $survey,
            'partner' => $partner,
            'form'    => $form->createView(),
        ]);
    }
}
