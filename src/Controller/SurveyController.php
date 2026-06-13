<?php

namespace App\Controller;

use App\Entity\Question;
use App\Entity\Survey;
use App\Form\SurveyType;
use App\Repository\SurveyAnswerRepository;
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
#[Route('/gestion/surveys')]
#[IsGranted('FEATURE_SURVEYS')]
#[IsGranted('ROLE_GESTION_ENCUESTAS')]
class SurveyController extends AbstractController
{
    /**
     * Listado de encuestas con el número de participantes de cada una.
     */
    #[Route('/', name: 'survey_index', methods: ['GET'])]
    public function index(Request $request, SurveyRepository $surveys, SurveyParticipationRepository $participations): Response
    {
        $showArchived = $request->query->getBoolean('archived');

        // Pocas encuestas: cargar ambos conjuntos es trivial y simplifica los
        // contadores (estado siempre sobre las activas; "archivadas" aparte).
        $active = $surveys->findBy(['archived' => false], ['createdAt' => 'DESC']);
        $archived = $surveys->findBy(['archived' => true], ['createdAt' => 'DESC']);

        // Participantes por encuesta en una sola consulta (sin N+1).
        $counts = $participations->countBySurvey();

        // Contadores por estado (sobre las activas) para la tira de cabecera.
        $statusCounts = [
            Survey::STATUS_DRAFT  => 0,
            Survey::STATUS_OPEN   => 0,
            Survey::STATUS_CLOSED => 0,
        ];
        foreach ($active as $survey) {
            ++$statusCounts[$survey->getStatus()];
        }

        return $this->render('survey/index.html.twig', [
            'surveys'        => $showArchived ? $archived : $active,
            'counts'         => $counts,
            'status_counts'  => $statusCounts,
            'total'          => count($active),
            'archived_count' => count($archived),
            'show_archived'  => $showArchived,
        ]);
    }

    /**
     * Ficha de una encuesta en SOLO LECTURA: su estructura (preguntas, tipos y
     * opciones) sea cual sea su estado. Para las en borrador, editar ya enseña
     * la estructura; esta vista es la única forma de ver una encuesta ya abierta
     * o cerrada, que no se editan.
     */
    #[Route('/{id}', name: 'survey_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Survey $survey, SurveyParticipationRepository $participations): Response
    {
        return $this->render('survey/show.html.twig', [
            'survey'        => $survey,
            'participants'  => $participations->countForSurvey($survey),
        ]);
    }

    /**
     * Resultados agregados y anónimos de una encuesta. Por pregunta:
     *   - single/multiple → recuento por opción.
     *   - scale           → recuento por valor 1-5 y media.
     *   - text            → lista de respuestas libres (no se agregan).
     *
     * Nunca se cruza una respuesta con quién la dio: solo agregados.
     */
    #[Route('/{id}/results', name: 'survey_results', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function results(Survey $survey, SurveyAnswerRepository $answers, SurveyParticipationRepository $participations): Response
    {
        $blocks = [];
        foreach ($survey->getQuestions() as $question) {
            $blocks[] = $this->resultBlock($question, $answers);
        }

        return $this->render('survey/results.html.twig', [
            'survey'       => $survey,
            'participants' => $participations->countForSurvey($survey),
            'blocks'       => $blocks,
        ]);
    }

    /**
     * Construye el bloque de resultados de una pregunta según su tipo.
     *
     * @return array<string, mixed>
     */
    private function resultBlock(Question $question, SurveyAnswerRepository $answers): array
    {
        $block = ['question' => $question, 'type' => $question->getType()];

        if ($question->usesOptions()) {
            $byOption = $answers->countByOption($question);
            $rows = [];
            foreach ($question->getOptions() as $option) {
                $rows[] = ['label' => $option->getLabel(), 'count' => $byOption[$option->getId()] ?? 0];
            }
            $block['options'] = $rows;

            return $block;
        }

        if (Question::TYPE_SCALE === $question->getType()) {
            $byValue = $answers->countByScaleValue($question);
            $rows = [];
            $sum = 0;
            $n = 0;
            for ($i = Question::SCALE_MIN; $i <= Question::SCALE_MAX; ++$i) {
                $count = $byValue[$i] ?? 0;
                $rows[] = ['value' => $i, 'count' => $count];
                $sum += $i * $count;
                $n += $count;
            }
            $block['scale'] = $rows;
            $block['scale_n'] = $n;
            $block['scale_avg'] = $n > 0 ? $sum / $n : null;

            return $block;
        }

        // Texto libre.
        $block['texts'] = $answers->findTextAnswers($question);

        return $block;
    }

    /**
     * Crear una encuesta nueva. Nace en borrador.
     */
    #[Route('/new', name: 'survey_new', methods: ['GET', 'POST'])]
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
    #[Route('/{id}/edit', name: 'survey_edit', methods: ['GET', 'POST'])]
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
    #[Route('/{id}/open', name: 'survey_open', methods: ['POST'])]
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
    #[Route('/{id}/close', name: 'survey_close', methods: ['POST'])]
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
     * Borrar la encuesta y, en cascada, sus preguntas y opciones. SOLO en
     * borrador: en cuanto se abre (o se cierra) puede haber votos y el dato no
     * se pierde — la opción entonces es archivar, no borrar. Un borrador nunca
     * tiene respuestas. Guard server-side, no solo ocultar el botón.
     */
    #[Route('/{id}/delete', name: 'survey_delete', methods: ['POST'])]
    public function delete(Request $request, Survey $survey, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('survey_delete_'.$survey->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('survey_index');
        }

        if (!$survey->isEditable()) {
            $this->addFlash('warning', 'Solo se pueden borrar encuestas en borrador. Si está abierta o cerrada, archívala (así no se pierden las respuestas).');

            return $this->redirectToRoute('survey_index');
        }

        $em->remove($survey);
        $em->flush();
        $this->addFlash('success', 'Encuesta borrada.');

        return $this->redirectToRoute('survey_index');
    }

    /**
     * Archivar: la oculta del listado sin borrar nada. Conserva preguntas y
     * respuestas. Reversible con {@see self::unarchive}.
     */
    #[Route('/{id}/archive', name: 'survey_archive', methods: ['POST'])]
    public function archive(Request $request, Survey $survey, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('survey_archive_'.$survey->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('survey_index');
        }

        $survey->setArchived(true);
        $em->flush();
        $this->addFlash('success', 'Encuesta archivada. Sigue guardada con sus respuestas; la ves en "Archivadas".');

        return $this->redirectToRoute('survey_index');
    }

    /**
     * Desarchivar: la devuelve al listado.
     */
    #[Route('/{id}/unarchive', name: 'survey_unarchive', methods: ['POST'])]
    public function unarchive(Request $request, Survey $survey, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('survey_unarchive_'.$survey->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('survey_index');
        }

        $survey->setArchived(false);
        $em->flush();
        $this->addFlash('success', 'Encuesta restaurada al listado.');

        return $this->redirectToRoute('survey_index');
    }

    /**
     * Fija la posición de cada pregunta y de cada una de sus opciones según el
     * orden en que llegan del formulario, para que se rendericen siempre en el
     * orden en que el equipo las colocó.
     */
    private function reindexQuestions(Survey $survey): void
    {
        $questionPosition = 0;
        foreach ($survey->getQuestions() as $question) {
            $question->setPosition($questionPosition++);

            $optionPosition = 0;
            foreach ($question->getOptions() as $option) {
                $option->setPosition($optionPosition++);
            }
        }
    }
}
