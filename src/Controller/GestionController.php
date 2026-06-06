<?php

namespace App\Controller;

use App\Form\ContactType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class GestionController extends AbstractController
{
    private const CONTACT_TO = 'csa@csavegadejarama.org';
    private const CONTACT_FROM = 'csa@csavegadejarama.org';

    public function index(): Response
    {
        return $this->render('Default/index.html.twig');
    }

    public function gestion(): Response
    {
        return $this->redirect($this->generateUrl('dashboard'));
    }

    /**
     * Renderiza el form de contacto como partial reutilizable desde el footer
     * de base_front (vía {{ render(controller(...)) }}).
     */
    public function contactWidget(): Response
    {
        $form = $this->createForm(ContactType::class, null, [
            'action' => $this->generateUrl('app_contact'),
        ]);

        return $this->render('frontend/_contact_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Procesa el envío del form de contacto. Llamado por POST a /contact.
     */
    public function contacted(
        Request $request,
        MailerInterface $mailer,
        #[Autowire(service: 'limiter.contact_form')]
        RateLimiterFactory $contactFormLimiter
    ): Response {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limit = $contactFormLimiter->create($request->getClientIp())->consume(1);
            if (!$limit->isAccepted()) {
                $this->addFlash('error', 'Has enviado demasiados mensajes en poco tiempo. Inténtalo de nuevo más tarde.');
                return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('homepage'));
            }

            $data = $form->getData();

            $email = (new MimeEmail())
                ->subject('[Contacto web] ' . $data['subject'])
                ->from(self::CONTACT_FROM)
                ->replyTo($data['email'])
                ->to(self::CONTACT_TO)
                ->html($this->renderView('Default/contact_email.html.twig', [
                    'name' => $data['name'],
                    'subject' => $data['subject'],
                    'body' => $data['body'],
                    'email' => $data['email'],
                ]));

            $mailer->send($email);

            $this->addFlash('notice', 'El mensaje se ha enviado correctamente. Te responderemos pronto.');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('homepage'));
    }
}
