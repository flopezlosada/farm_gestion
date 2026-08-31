<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La bandeja de avisos: lo que se te ha avisado, esté o no leído.
 *
 * FUERA DE `/panel` Y SIN PEDIR ROLE_PARTNER, aunque el sitio natural pareciera
 * el panel del socix. La bandeja es de la CUENTA y no de una sección: el aviso de
 * que hay fichas de socix con datos que faltan va a quien coordina socixs, que
 * puede ser una cuenta de gestión sin ficha de socix detrás. Colgada de `/panel`
 * habría sido una bandeja que su destinatario no puede abrir. La plantilla elige
 * el shell según quién entra, para que nadie termine navegando un menú que no es
 * el suyo.
 *
 * UN AVISO SE MARCA LEÍDO AL ABRIRLO, no al abrir la bandeja. Así la campanita
 * sigue contando lo que de verdad no se ha mirado: pasar por delante de una lista
 * no es haber leído nada, y con lo contrario bastaría entrar una vez para que el
 * contador se vaciara y el aviso se perdiera de vista sin haberse leído.
 */
#[Route('/avisos')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class NotificationController extends AbstractController
{
    /**
     * La bandeja: los avisos de quien entra, del más reciente al más antiguo.
     *
     * @param User                   $user          la cuenta que mira
     * @param NotificationRepository $notifications repositorio de avisos
     *
     * @return Response la bandeja
     */
    #[Route('', name: 'notification_inbox', methods: ['GET'])]
    public function inbox(#[CurrentUser] User $user, NotificationRepository $notifications): Response
    {
        return $this->render('Notification/inbox.html.twig', [
            'notifications' => $notifications->findRecentFor($user),
        ]);
    }

    /**
     * Abre un aviso: lo marca leído y lleva a la pantalla que lo contesta.
     *
     * El destino sale de {@see NotificationLink}, la MISMA fuente que el payload
     * del push, para que la fila y la notificación del móvil del mismo aviso no
     * puedan llevar a sitios distintos.
     *
     * @param Notification            $notification  el aviso que se abre
     * @param User                    $user          la cuenta que lo abre
     * @param EntityManagerInterface  $entityManager para guardar la lectura
     * @param NotificationLink        $link          de dónde sale el destino
     *
     * @return Response la redirección al destino del aviso
     */
    #[Route('/{id}', name: 'notification_open', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function open(
        Notification $notification,
        #[CurrentUser] User $user,
        EntityManagerInterface $entityManager,
        NotificationLink $link,
    ): Response {
        // Un aviso es de una persona y de nadie más. Sin esta comprobación, el id
        // en la URL deja leer los avisos de cualquiera, y en ellos van la fecha y
        // el punto de recogida de otro socix.
        if ($notification->getRecipient() !== $user) {
            throw $this->createAccessDeniedException('Este aviso no es tuyo.');
        }

        if (!$notification->isRead()) {
            $notification->markRead();
            $entityManager->flush();
        }

        return $this->redirect($link->pathFor($notification));
    }
}
