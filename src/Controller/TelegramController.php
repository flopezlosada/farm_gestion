<?php

namespace App\Controller;

use App\Entity\TelegramLink;
use App\Entity\User;
use App\Form\TelegramInviteType;
use App\Repository\TelegramLinkRepository;
use App\Service\Telegram\TelegramApiException;
use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramInvitationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Quién puede usar el bot de Telegram: invitar a una persona (un enlace que abre en
 * Telegram y la deja vinculada) y retirar a quien ya no deba usarlo.
 *
 * Es de administración y no de una sección porque el bot sirve a varias: estar
 * vinculado no da permisos por sí mismo, cada cosa que hace el bot comprueba los
 * suyos ({@see \App\Service\Telegram\Destination\TelegramDestination::accepts()}).
 */
#[Route('/gestion/telegram')]
#[IsGranted('ROLE_ADMIN')]
class TelegramController extends AbstractController
{
    /**
     * Las personas vinculadas e invitadas, y el formulario para invitar.
     */
    #[Route('', name: 'telegram_index', methods: ['GET'])]
    public function index(TelegramLinkRepository $links, TelegramBotApi $api, CacheInterface $cache): Response
    {
        $bot = $this->botUsername($api, $cache);

        return $this->render('telegram/index.html.twig', [
            'links' => $links->findAllWithUser(),
            'bot' => $bot,
            'enabled' => $api->isEnabled(),
            'now' => new \DateTimeImmutable(),
            'form' => $this->createForm(TelegramInviteType::class, null, [
                'action' => $this->generateUrl('telegram_invite'),
            ])->createView(),
        ]);
    }

    /**
     * Prepara la invitación de una persona y se la manda por correo. Si ya estaba
     * vinculada, la nueva invitación sirve para pasar a otra cuenta de Telegram; la
     * vieja sigue valiendo hasta que se use la nueva.
     */
    #[Route('/invite', name: 'telegram_invite', methods: ['POST'])]
    public function invite(
        Request $request,
        TelegramLinkRepository $links,
        EntityManagerInterface $em,
        TelegramBotApi $api,
        CacheInterface $cache,
        TelegramInvitationMailer $mailer,
    ): Response {
        $form = $this->createForm(TelegramInviteType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }

            return $this->redirectToRoute('telegram_index');
        }

        /** @var User $user */
        $user = $form->get('user')->getData();
        $link = $links->findForUser($user);
        if ($link === null) {
            $link = new TelegramLink($user);
            $em->persist($link);
        }
        $link->invite(new \DateTimeImmutable());
        $em->flush();

        $this->mailInvitation($link, $api, $cache, $mailer);

        return $this->redirectToRoute('telegram_index');
    }

    /**
     * Vuelve a mandar por correo la invitación vigente (no crea una nueva: para
     * eso, volver a invitar).
     */
    #[Route('/{id}/send', name: 'telegram_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function send(TelegramLink $link, Request $request, TelegramBotApi $api, CacheInterface $cache, TelegramInvitationMailer $mailer): Response
    {
        if (!$this->isCsrfTokenValid('telegram_send_' . $link->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');
        } elseif (!$link->isInvitationValid(new \DateTimeImmutable())) {
            $this->addFlash('danger', 'Esa invitación ya se usó o ha caducado. Invita de nuevo para generar otra.');
        } else {
            $this->mailInvitation($link, $api, $cache, $mailer);
        }

        return $this->redirectToRoute('telegram_index');
    }

    /**
     * Manda la invitación y dice cómo ha ido. Si no se puede (sin bot, sin correo),
     * el enlace sigue en la tabla para pasarlo a mano.
     */
    private function mailInvitation(TelegramLink $link, TelegramBotApi $api, CacheInterface $cache, TelegramInvitationMailer $mailer): void
    {
        $user = $link->getUser();
        $bot = $this->botUsername($api, $cache);

        if ($bot === null) {
            $this->addFlash('warning', sprintf('Invitación preparada para %s, pero no se ha podido mandar: el bot no está configurado o Telegram no contesta.', $user->getDisplayName()));
        } elseif (!$mailer->send($link, $bot)) {
            $this->addFlash('warning', sprintf('%s no tiene correo en su cuenta. Pásale el enlace de la tabla por otro lado: vale %d días y un solo uso.', $user->getDisplayName(), TelegramLink::INVITATION_DAYS));
        } else {
            $this->addFlash('success', sprintf('Invitación enviada a %s (%s). Tiene que abrir el correo en el móvil donde usa Telegram.', $user->getDisplayName(), $user->getEmail()));
        }
    }

    /**
     * Retira a una persona del bot: deja de atenderla al momento. Lo que ya mandó
     * se queda donde esté.
     */
    #[Route('/{id}/remove', name: 'telegram_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(TelegramLink $link, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('telegram_remove_' . $link->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La página había caducado. Vuelve a intentarlo.');

            return $this->redirectToRoute('telegram_index');
        }

        $name = $link->getUser()->getDisplayName();
        $em->remove($link);
        $em->flush();

        $this->addFlash('success', sprintf('%s ya no puede usar el bot.', $name));

        return $this->redirectToRoute('telegram_index');
    }

    /**
     * El @nombre del bot, que hace falta para el enlace. Se pregunta a Telegram (así
     * no hay que configurarlo aparte y, de paso, se sabe si el token vale) y se
     * recuerda un día. Null si no hay bot o Telegram no contesta.
     */
    private function botUsername(TelegramBotApi $api, CacheInterface $cache): ?string
    {
        if (!$api->isEnabled()) {
            return null;
        }

        try {
            return $cache->get('telegram_bot_username_' . substr($api->webhookSecret(), 0, 12), static function (ItemInterface $item) use ($api): string {
                $item->expiresAfter(86400);

                return (string) $api->getMe()['username'];
            });
        } catch (TelegramApiException) {
            return null;
        }
    }
}
