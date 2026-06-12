<?php

namespace App\Controller;

use App\Entity\Survey;
use App\Form\SurveyType;
use App\Repository\SurveyParticipationRepository;
use App\Repository\SurveyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestión de encuestas internas (crear, abrir, cerrar, borrar) para el equipo.
 * Responder es cosa del panel del socix; ver {@see \App\Controller} del panel.
 *
 * Acceso restringido a ROLE_GESTION_ENCUESTAS, que NO incluye los datos
 * personales de socixs (mínimo privilegio): aquí sólo se manejan encuestas y
 * resultados agregados, nunca quién respondió qué.
 */
#[Route('/gestion/encuestas')]
#[IsGranted('ROLE_GESTION_ENCUESTAS')]
class SurveyController extends AbstractController
{
    /**
     * Listado de encuestas con el número de participantes de cada una.
     */
    #[Route('/', name: 'survey_index', methods: ['GET'])]
    public function index(SurveyRepository $surveys, SurveyParticipationRepository $participations): Response
    {
        $all = $surveys->findBy([], ['createdAt' => 'DESC']);

        $counts = [];
        foreach ($all as $survey) {
            $counts[$survey->getId()] = $participations->countForSurvey($survey);
        }

        return $this->render('survey/index.html.twig', [
            'surveys' => $all,
            'counts'  => $counts,
        ]);
    }

    /**
     * Crear una encuesta nueva. Nace en borrador.
     */
    #[Route('/nueva', name: 'survey_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $survey = new Survey();
        $form = $this->createForm(SurveyType::class, $survey);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->reindexQuestions($survey);
            $em->persist($survey);
            $em->flush();
            $this->addFlash('success', 'Encuesta creada en borrador.');

            return $this->redirectToRoute('survey_index');
        }

        return $this->render('survey/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Editar una encuesta. Sólo en borrador: una vez abierta, tocar las
     * preguntas invalidaría las respuestas ya recogidas.
     */
    #[Route('/{id}/editar', name: 'survey_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Survey $survey, EntityManagerInterface $em): Response
    {
        if (!$survey->isEditable()) {
            $this->addFlash('warning', 'Sólo se pueden editar encuestas en borrador.');

            return $this->redirectToRoute('survey_index');
        }

        $form = $this->createForm(SurveyType::class, $survey);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->reindexQuestions($survey);
            $em->flush();
            $this->addFlash('success', 'Encuesta actualizada.');

            return $this->redirectToRoute('survey_index');
        }

        return $this->render('survey/edit.html.twig', [
            'survey' => $survey,
            'form'   => $form->createView(),
        ]);
    }

    /**
     * Abrir la encuesta a respuestas. A partir de aquí ya no se edita.
     */
    #[Route('/{id}/abrir', name: 'survey_open', methods: ['POST'])]
    public function open(Request $request, Survey $survey, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('survey_open_'.$survey->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('survey_index');
        }

        if ($survey->getQuestions()->isEmpty()) {
            $this->addFlash('warning', 'No se puede abrir una encuesta sin preguntas.');

            return $this->redirectToRoute('survey_index');
        }

        $survey->setStatus(Survey::STATUS_OPEN);
        $em->flush();
        $this->addFlash('success', 'Encuesta abierta: lxs socixs ya pueden responder.');

        return $this->redirectToRoute('survey_index');
    }

    /**
     * Cerrar la encuesta. Deja de admitir respuestas; sólo quedan resultados.
     */
    #[Route('/{id}/cerrar', name: 'survey_close', methods: ['POST'])]
    public function close(Request $request, Survey $survey, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('survey_close_'.$survey->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('survey_index');
        }

        $survey->setStatus(Survey::STATUS_CLOSED);
        $em->flush();
        $this->addFlash('success', 'Encuesta cerrada.');

        return $this->redirectToRoute('survey_index');
    }

    /**
     * Borrar la encuesta y, en cascada, sus preguntas, opciones y respuestas.
     */
    #[Route('/{id}/borrar', name: 'survey_delete', methods: ['POST'])]
    public function delete(Request $request, Survey $survey, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('survey_delete_'.$survey->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('survey_index');
        }

        $em->remove($survey);
        $em->flush();
        $this->addFlash('success', 'Encuesta borrada.');

        return $this->redirectToRoute('survey_index');
    }

    /**
     * Fija la posición de cada pregunta según su orden en el formulario, para
     * que se rendericen siempre en el orden en que el equipo las colocó.
     */
    private function reindexQuestions(Survey $survey): void
    {
        $position = 0;
        foreach ($survey->getQuestions() as $question) {
            $question->setPosition($position++);
        }
    }
}
