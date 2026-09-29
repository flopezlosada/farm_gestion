<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\Question;
use App\Entity\Survey;
use App\Repository\PartnerRepository;
use App\Form\SurveyResponseType;
use App\Form\SurveyType;
use App\Repository\SurveyAnswerRepository;
use App\Repository\SurveyParticipationRepository;
use App\Repository\SurveyRepository;
use App\Service\Survey\SurveyAnnouncer;
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
    public function index(
        Request $request,
        SurveyRepository $surveys,
        SurveyParticipationRepository $participations,
        SurveyAnnouncer $announcer,
    ): Response {
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
            // A cuánta gente llegará el aviso al abrir: se enseña en el diálogo
            // ANTES de pulsar. Sólo si hay algún borrador que abrir.
            'audience'       => $statusCounts[Survey::STATUS_DRAFT] > 0 ? $announcer->audience() : null,
            'now'            => new \DateTimeImmutable(),
        ]);
    }

    /**
     * Ficha de una encuesta en SOLO LECTURA: su estructura (preguntas, tipos y
     * opciones) sea cual sea su estado. Para las en borrador, editar ya enseña
     * la estructura; esta vista es la única forma de ver una encuesta ya abierta
     * o cerrada, que no se editan.
     */
    #[Route('/{id}', name: 'survey_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Survey $survey,
        SurveyParticipationRepository $participations,
        PartnerRepository $partners,
        SurveyAnnouncer $announcer,
    ): Response {
        $now = new \DateTimeImmutable();
        $canWrite = $this->isGranted('ROLE_GESTION_ENCUESTAS_EDIT');

        return $this->render('survey/show.html.twig', [
            'survey'          => $survey,
            'participants'    => $participations->countForSurvey($survey),
            // A quién se le puede reenviar el enlace: sólo mientras admite
            // respuestas y a quien puede escribir. Nombres, nada más: este rol
            // no ve fichas ni correos de socias.
            'resend_partners' => $canWrite && $survey->acceptsResponses($now) ? $partners->findActiveWithEmail() : [],
            // Abrir desde la ficha: es donde se revisa antes. El diálogo dice a
            // cuánta gente llegará el aviso.
            'can_open'        => $canWrite && $survey->isEditable(),
            'audience'        => $canWrite && $survey->isEditable() ? $announcer->audience() : null,
            // Avisar a todas: sólo si está abierta y NUNCA se avisó.
            'can_announce'    => $canWrite && $survey->acceptsResponses($now) && null === $survey->getAnnouncedAt(),
        ]);
    }

    /**
     * Vista previa: la encuesta EXACTAMENTE como la ve la socia, con el mismo
     * layout, cabecera y formulario que el enlace del correo. No se puede
     * enviar. Vale con el rol de lectura: sólo se mira.
     */
    #[Route('/{id}/preview', name: 'survey_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function preview(Survey $survey): Response
    {
        $form = $this->createForm(SurveyResponseType::class, null, ['survey' => $survey]);

        return $this->render('survey/preview.html.twig', [
            'survey' => $survey,
            'form'   => $form->createView(),
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
    #[IsGranted('ROLE_GESTION_ENCUESTAS_EDIT')]
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
    #[IsGranted('ROLE_GESTION_ENCUESTAS_EDIT')]
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
     * Abrir la encuesta a respuestas y avisar a la asociación. A partir de aquí
     * ya no se edita.
     *
     * El aviso sale en la misma petición, como el del grupo de consumo. Si el
     * envío se corta a mitad, {@see self::announce()} lo completa sin repetir a
     * nadie.
     */
    #[Route('/{id}/open', name: 'survey_open', methods: ['POST'])]
    public function open(Request $request, Survey $survey, EntityManagerInterface $em, SurveyAnnouncer $announcer): Response
    {
        if (!$this->isCsrfTokenValid('survey_open_'.$survey->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('survey_index');
        }

        $problem = $survey->whyCannotOpen(new \DateTimeImmutable());
        if (null !== $problem) {
            $this->addFlash('warning', $problem);

            return $this->redirectToRoute('survey_index');
        }

        // Primero abrirla, luego avisar: un aviso de una encuesta que aún no
        // admite respuestas mandaría a la gente a un «ya no está abierta».
        $survey->setStatus(Survey::STATUS_OPEN);
        $em->flush();

        $sent = $announcer->announce($survey);
        $this->addFlash('success', sprintf(
            'Encuesta abierta. Aviso enviado: %d por correo, %d al móvil y en la bandeja de %d cuentas.',
            $sent['email'],
            $sent['push'],
            $sent['inbox'],
        ));

        return $this->redirectToRoute('survey_index');
    }

    /**
     * Avisar de una encuesta abierta que TODAVÍA NO SE HA AVISADO: la que se
     * abrió antes de que existiera el aviso, o un envío que se cortó antes de
     * terminar (la marca se pone al final). Una vez avisada no se repite: el
     * reenvío es a una socia concreta, desde la ficha ({@see self::resend()}).
     */
    #[Route('/{id}/announce', name: 'survey_announce', methods: ['POST'])]
    public function announce(Request $request, Survey $survey, SurveyAnnouncer $announcer): Response
    {
        if (!$this->isCsrfTokenValid('survey_announce_'.$survey->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('survey_index');
        }

        if (!$survey->acceptsResponses(new \DateTimeImmutable())) {
            $this->addFlash('warning', 'Esta encuesta ya no admite respuestas: no tiene sentido avisar.');

            return $this->redirectToRoute('survey_index');
        }

        if (null !== $survey->getAnnouncedAt()) {
            $this->addFlash('warning', 'Esta encuesta ya se avisó a la asociación. Si a alguien no le llegó, reenvíale su enlace desde la ficha de la encuesta.');

            return $this->redirectToRoute('survey_index');
        }

        $sent = $announcer->announce($survey);
        $this->addFlash('success', sprintf(
            'Aviso enviado: %d por correo, %d al móvil y en la bandeja de %d cuentas.',
            $sent['email'],
            $sent['push'],
            $sent['inbox'],
        ));

        return $this->redirectToRoute('survey_index');
    }

    /**
     * Reenviar el correo, con su enlace personal, a UNA socia que dice que no
     * le llegó. No dice si ya había respondido: el equipo de encuestas no debe
     * saber quién ha participado. Si ya respondió, el enlace le dará las gracias.
     */
    #[Route('/{id}/resend', name: 'survey_resend', methods: ['POST'])]
    public function resend(Request $request, Survey $survey, PartnerRepository $partners, SurveyAnnouncer $announcer): Response
    {
        $back = $this->redirectToRoute('survey_show', ['id' => $survey->getId()]);

        if (!$this->isCsrfTokenValid('survey_resend_'.$survey->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $back;
        }

        if (!$survey->acceptsResponses(new \DateTimeImmutable())) {
            $this->addFlash('warning', 'Esta encuesta ya no admite respuestas: el enlace no le serviría.');

            return $back;
        }

        $partner = $partners->find($request->request->getInt('partner'));
        if (!$partner instanceof Partner || Partner::STATUS_ACTIVO !== $partner->getStatus() || '' === trim((string) $partner->getEmail())) {
            $this->addFlash('warning', 'Elige una socia activa con correo.');

            return $back;
        }

        $name = trim(mb_convert_case((string) $partner->getName(), MB_CASE_TITLE) . ' ' . mb_convert_case((string) $partner->getSurname(), MB_CASE_TITLE));
        $announcer->resendTo($survey, $partner)
            ? $this->addFlash('success', sprintf('Enlace reenviado a %s.', $name))
            : $this->addFlash('warning', sprintf('No se pudo enviar el correo a %s. Revisa que el envío de correos esté encendido y mira el registro de avisos.', $name));

        return $back;
    }

    /**
     * Cerrar la encuesta antes de su plazo. Deja de admitir respuestas; sólo
     * quedan resultados. Sólo desde abierta: un borrador se borra, no se cierra.
     */
    #[Route('/{id}/close', name: 'survey_close', methods: ['POST'])]
    public function close(Request $request, Survey $survey, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('survey_close_'.$survey->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('survey_index');
        }

        if (!$survey->isOpen()) {
            $this->addFlash('warning', 'Sólo se puede cerrar una encuesta abierta.');

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
