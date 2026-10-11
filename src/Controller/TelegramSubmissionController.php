<?php

namespace App\Controller;

use App\Entity\TelegramSubmission;
use App\Entity\User;
use App\Repository\TelegramSubmissionRepository;
use App\Service\Telegram\TelegramFileStore;
use App\Service\Telegram\TelegramSubmissionRouter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lo que ha llegado al bot, para corregirlo desde la web: lo que el bot no tenía
 * claro (pendiente, esperando a que alguien diga qué es) y lo último que colocó
 * solo, por si se equivocó de apartado.
 *
 * Encaminar desde aquí hace lo mismo que el botón en Telegram
 * ({@see TelegramSubmissionRouter}), y a quien lo mandó le llega el aviso en su
 * chat.
 *
 * De administración, como la pantalla del bot: el bot sirve a varias secciones.
 * Cuando haya apartados de varias, cada sección podrá ver los suyos.
 */
#[Route('/gestion/telegram/envios')]
#[IsGranted('ROLE_ADMIN')]
class TelegramSubmissionController extends AbstractController
{
    /** Cuántos ya resueltos se enseñan. */
    private const RECENT = 30;

    /**
     * Los pendientes y los últimos resueltos.
     */
    #[Route('', name: 'telegram_submissions', methods: ['GET'])]
    public function index(TelegramSubmissionRepository $submissions, TelegramSubmissionRouter $router): Response
    {
        $pending = $submissions->findPending();
        $recent = $submissions->findRecentResolved(self::RECENT);

        $options = [];
        $labels = [];
        foreach ([...$pending, ...$recent] as $submission) {
            $candidates = $router->candidatesFor($submission);
            $options[$submission->getId()] = $candidates;
            foreach ($candidates as $destination) {
                $labels[$destination->key()] = $destination->label();
            }
        }
        foreach ($recent as $submission) {
            $key = $submission->getDestination();
            if ($key !== null && !isset($labels[$key])) {
                $labels[$key] = $router->destination($key)?->label() ?? $key;
            }
        }

        return $this->render('telegram/submissions.html.twig', [
            'pending' => $pending,
            'recent' => $recent,
            'options' => $options,
            'labels' => $labels,
        ]);
    }

    /**
     * El fichero de un envío, para verlo antes de decidir.
     */
    #[Route('/{id}/file', name: 'telegram_submission_file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function document(TelegramSubmission $submission, TelegramFileStore $files): Response
    {
        $path = $files->pathTo($submission->getFileName());
        if ($path === null) {
            throw $this->createNotFoundException('El fichero de este envío no está en el servidor.');
        }

        $response = new BinaryFileResponse($path);
        if ($submission->getMimeType() !== null) {
            $response->headers->set('Content-Type', $submission->getMimeType());
        }
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, (string) $submission->getOriginalName(), 'envio');

        return $response;
    }

    /**
     * Lo manda a un apartado (o lo cambia de apartado).
     */
    #[Route('/{id}/route', name: 'telegram_submission_route', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function route(TelegramSubmission $submission, Request $request, TelegramSubmissionRouter $router): Response
    {
        if (!$this->isCsrfTokenValid('telegram_submission_' . $submission->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('telegram_submissions');
        }

        $key = (string) $request->request->get('destination');
        $destination = null;
        foreach ($router->candidatesFor($submission) as $candidate) {
            if ($candidate->key() === $key) {
                $destination = $candidate;
            }
        }
        if ($destination === null) {
            $this->addFlash('danger', 'Ese apartado no admite este envío, o la persona que lo mandó no puede usarlo.');

            return $this->redirectToRoute('telegram_submissions');
        }

        $problem = $router->route($submission, $destination, $this->actor());
        $problem === null
            ? $this->addFlash('success', sprintf('Guardado como «%s». Se lo hemos dicho a %s por Telegram.', $destination->label(), $submission->getUser()->getDisplayName()))
            : $this->addFlash('danger', $problem);

        return $this->redirectToRoute('telegram_submissions');
    }

    /**
     * No era nada que guardar.
     */
    #[Route('/{id}/discard', name: 'telegram_submission_discard', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function discard(TelegramSubmission $submission, Request $request, TelegramSubmissionRouter $router): Response
    {
        if (!$this->isCsrfTokenValid('telegram_submission_' . $submission->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('telegram_submissions');
        }

        $problem = $router->discard($submission, $this->actor());
        $problem === null
            ? $this->addFlash('success', 'Descartado.')
            : $this->addFlash('danger', $problem);

        return $this->redirectToRoute('telegram_submissions');
    }

    private function actor(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
