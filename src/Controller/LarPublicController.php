<?php

namespace App\Controller;

use App\Entity\Image;
use App\Entity\LarProject;
use App\Form\LarContactType;
use App\Repository\LarProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cara pública del LAR (Laboratorio Agroecológico Rural): la landing con la
 * descripción del espacio, la oferta formativa y los proyectos publicados, el
 * detalle de cada proyecto, y el formulario de petición de información que
 * acaba en larcsa@csavegadejarama.org.
 *
 * Es público (sin login): no lleva #[IsGranted] de clase y no hay ninguna
 * regla en access_control que cubra /lar, así que Symfony lo deja abierto.
 * Va en un controller aparte del de gestión ({@see LarController}) porque ese
 * sí exige rol de clase.
 */
class LarPublicController extends AbstractController
{
    /** Buzón de la coordinación del LAR (destino del formulario). */
    private const LAR_TO = 'larcsa@csavegadejarama.org';

    /** Remitente: debe ser del dominio autenticado (SPF/DKIM) para no ir a spam. */
    private const LAR_FROM = 'csa@csavegadejarama.org';

    /**
     * Landing pública del LAR (/lar): descripción, oferta formativa, proyectos
     * activos y finalizados publicados, y el formulario de contacto.
     *
     * @param LarProjectRepository $projects Repositorio de proyectos LAR.
     * @return Response
     */
    #[Route('/lar', name: 'lar_public', methods: ['GET'])]
    public function lar(LarProjectRepository $projects): Response
    {
        $form = $this->createForm(LarContactType::class, null, [
            'action' => $this->generateUrl('lar_public_contact'),
        ]);

        return $this->render('frontend/lar.html.twig', [
            'active' => $projects->findPublishedByStatus(LarProject::STATUS_ACTIVE),
            'finished' => $projects->findPublishedByStatus(LarProject::STATUS_FINISHED),
            'form' => $form->createView(),
        ]);
    }

    /**
     * Detalle público de un proyecto (/lar/proyecto/{slug}): cuerpo completo y
     * galería de fotos. 404 si no existe o está en borrador.
     *
     * @param string $slug Slug del proyecto.
     * @param LarProjectRepository $projects
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/lar/proyecto/{slug}', name: 'lar_public_project', methods: ['GET'])]
    public function project(string $slug, LarProjectRepository $projects, EntityManagerInterface $em): Response
    {
        $project = $projects->findPublishedBySlug($slug);
        if (!$project) {
            throw $this->createNotFoundException('Proyecto no encontrado.');
        }

        return $this->render('frontend/lar_project.html.twig', [
            'project' => $project,
            'photos' => $em->getRepository(Image::class)->findForObject(LarProject::OBJECT_CLASS, $project->getId()),
        ]);
    }

    /**
     * Procesa el formulario de petición de información y envía el correo a la
     * coordinación del LAR. Rate-limited (reutiliza el limiter del formulario
     * de contacto general: 3/hora/IP) para evitar mail-bombing.
     *
     * @param Request $request
     * @param MailerInterface $mailer
     * @param RateLimiterFactory $contactFormLimiter
     * @return Response
     */
    #[Route('/lar/contacto', name: 'lar_public_contact', methods: ['POST'])]
    public function contacted(
        Request $request,
        MailerInterface $mailer,
        #[Autowire(service: 'limiter.contact_form')]
        RateLimiterFactory $contactFormLimiter
    ): Response {
        $form = $this->createForm(LarContactType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limit = $contactFormLimiter->create($request->getClientIp())->consume(1);
            if (!$limit->isAccepted()) {
                $this->addFlash('error', 'Has enviado demasiadas solicitudes en poco tiempo. Inténtalo de nuevo más tarde.');

                return $this->redirectToRoute('lar_public');
            }

            $data = $form->getData();
            $requestLabel = LarContactType::REQUESTS[$data['requestType']] ?? $data['requestType'];

            $email = (new MimeEmail())
                ->subject('[LAR] ' . $requestLabel . ' — ' . $data['name'])
                ->from(self::LAR_FROM)
                ->replyTo($data['email'])
                ->to(self::LAR_TO)
                ->html($this->renderView('email/lar_contact.html.twig', [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'request_type' => $requestLabel,
                    'group_size' => $data['groupSize'] ?? null,
                    'preferred_dates' => $data['preferredDates'] ?? null,
                    'body' => $data['body'],
                ]));

            $mailer->send($email);

            $this->addFlash('notice', 'Hemos recibido tu solicitud. La coordinación del LAR te responderá pronto.');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('lar_public');
    }
}
