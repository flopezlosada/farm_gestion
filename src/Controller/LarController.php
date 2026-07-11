<?php

namespace App\Controller;

use App\Entity\Image;
use App\Entity\LarProject;
use App\Form\ImageType;
use App\Form\LarPageType;
use App\Form\LarProjectType;
use App\Repository\LarPageRepository;
use App\Repository\LarProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Gestión de los proyectos del LAR (Laboratorio Agroecológico Rural) desde el
 * panel /gestion. CRUD de la ficha + galería de fotos.
 *
 * Modelo de permisos igual que albergue/laboral: la LECTURA la da
 * ROLE_GESTION_LAR (el #[IsGranted] de clase y la regla ^/gestion de
 * security.yaml); la ESCRITURA se exige por método HTTP en access_control
 * sobre ^/gestion/lar, por eso toda mutación es POST.
 *
 * La galería reutiliza la entidad {@see Image} (media polimórfica por
 * object_class + foreign_key), pero NO el ImageController del blog: ese está
 * atado a ROLE_BLOG y a la maquinaria AJAX del editor de posts. Aquí la subida
 * y el borrado son acciones propias, con el permiso de LAR.
 */
#[Route('/gestion/lar')]
#[IsGranted('ROLE_GESTION_LAR')]
class LarController extends AbstractController
{
    /**
     * Listado de proyectos para gestión.
     *
     * @param LarProjectRepository $projects Repositorio de proyectos LAR.
     * @return Response
     */
    #[Route('', name: 'lar_index', methods: ['GET'])]
    public function index(LarProjectRepository $projects): Response
    {
        $all = $projects->findAllOrdered();

        return $this->render('lar/index.html.twig', [
            'projects' => $all,
            'stats' => [
                'total' => count($all),
                'published' => count(array_filter($all, static fn (LarProject $p) => $p->isPublished())),
                'active' => count(array_filter($all, static fn (LarProject $p) => $p->isActive())),
            ],
        ]);
    }

    /**
     * Edición del contenido de la portada del LAR (singleton {@see LarPage}): la
     * introducción, los datos de contacto y las tarjetas de oferta formativa. La
     * fila se crea la primera vez que se guarda; hasta entonces se edita la
     * instancia con el contenido de fábrica.
     *
     * @param Request $request
     * @param LarPageRepository $pages
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/page', name: 'lar_page', methods: ['GET', 'POST'])]
    public function page(Request $request, LarPageRepository $pages, EntityManagerInterface $em): Response
    {
        $page = $pages->get();
        $form = $this->createForm(LarPageType::class, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($page);
            $em->flush();
            $this->addFlash('success', 'Portada actualizada.');

            return $this->redirectToRoute('lar_page');
        }

        return $this->render('lar/page.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Alta de un proyecto. Tras crearlo lleva a la edición, donde se añaden las
     * fotos.
     *
     * @param Request $request
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/new', name: 'lar_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $project = new LarProject();
        $form = $this->createForm(LarProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($project);
            $em->flush();
            $this->addFlash('success', sprintf('Proyecto «%s» creado. Ahora puedes añadir fotos.', $project->getTitle()));

            return $this->redirectToRoute('lar_edit', ['id' => $project->getId()]);
        }

        return $this->render('lar/new.html.twig', [
            'project' => $project,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Edición de la ficha + gestión de la galería de fotos.
     *
     * @param Request $request
     * @param LarProject $project Proyecto resuelto por el id de la ruta.
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}/edit', name: 'lar_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, LarProject $project, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(LarProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Proyecto actualizado.');

            return $this->redirectToRoute('lar_edit', ['id' => $project->getId()]);
        }

        return $this->render('lar/edit.html.twig', [
            'project' => $project,
            'form' => $form->createView(),
            'photos' => $this->photosOf($project, $em),
            'photo_form' => $this->buildPhotoForm($project)->createView(),
        ]);
    }

    /**
     * Borrado de un proyecto. Sus fotos se borran también (incluyendo el
     * fichero en disco, vía el PostRemove de {@see Image}).
     *
     * @param Request $request
     * @param LarProject $project
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}', name: 'lar_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, LarProject $project, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $project->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('lar_edit', ['id' => $project->getId()]);
        }

        foreach ($this->photosOf($project, $em) as $photo) {
            $em->remove($photo);
        }
        $title = $project->getTitle();
        $em->remove($project);
        $em->flush();

        $this->addFlash('success', sprintf('Proyecto «%s» borrado.', $title));

        return $this->redirectToRoute('lar_index');
    }

    /**
     * Sube una foto y la asocia al proyecto (media polimórfica).
     *
     * @param Request $request
     * @param LarProject $project
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}/photo', name: 'lar_photo_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadPhoto(Request $request, LarProject $project, EntityManagerInterface $em): Response
    {
        $image = new Image();
        $form = $this->buildPhotoForm($project, $image);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $image->setObjectClass(LarProject::OBJECT_CLASS);
            $image->setForeignKey((string) $project->getId());
            $image->setSingle(false);
            $em->persist($image);
            $em->flush();
            $this->addFlash('success', 'Foto añadida.');
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        return $this->redirectToRoute('lar_edit', ['id' => $project->getId()]);
    }

    /**
     * Borra una foto del proyecto. Comprueba que la imagen pertenece de verdad
     * a este proyecto antes de borrarla (defensa ante ids manipulados).
     *
     * @param Request $request
     * @param LarProject $project
     * @param int $imageId
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}/photo/{imageId}', name: 'lar_photo_delete', methods: ['POST'], requirements: ['id' => '\d+', 'imageId' => '\d+'])]
    public function deletePhoto(Request $request, LarProject $project, int $imageId, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_photo' . $imageId, (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('lar_edit', ['id' => $project->getId()]);
        }

        $image = $em->getRepository(Image::class)->find($imageId);
        if ($image
            && $image->getObjectClass() === LarProject::OBJECT_CLASS
            && $image->getForeignKey() === (string) $project->getId()
        ) {
            $em->remove($image);
            $em->flush();
            $this->addFlash('success', 'Foto borrada.');
        }

        return $this->redirectToRoute('lar_edit', ['id' => $project->getId()]);
    }

    /**
     * Renombra el título de una foto del proyecto. Comprueba que la imagen
     * pertenece de verdad a este proyecto (defensa ante ids manipulados) y valida
     * la entidad {@see Image} antes de guardar, para respetar sus restricciones
     * (p. ej. la longitud mínima del título) sin duplicarlas aquí.
     *
     * @param Request $request
     * @param LarProject $project
     * @param int $imageId
     * @param EntityManagerInterface $em
     * @param ValidatorInterface $validator
     * @return Response
     */
    #[Route('/{id}/photo/{imageId}/rename', name: 'lar_photo_rename', methods: ['POST'], requirements: ['id' => '\d+', 'imageId' => '\d+'])]
    public function renamePhoto(Request $request, LarProject $project, int $imageId, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        if (!$this->isCsrfTokenValid('rename_photo' . $imageId, (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('lar_edit', ['id' => $project->getId()]);
        }

        $image = $em->getRepository(Image::class)->find($imageId);
        if ($image
            && $image->getObjectClass() === LarProject::OBJECT_CLASS
            && $image->getForeignKey() === (string) $project->getId()
        ) {
            $image->setTitle(trim((string) $request->request->get('title')));
            $errors = $validator->validate($image);
            if (count($errors) > 0) {
                $this->addFlash('error', $errors->get(0)->getMessage());
            } else {
                $em->flush();
                $this->addFlash('success', 'Título de la foto actualizado.');
            }
        }

        return $this->redirectToRoute('lar_edit', ['id' => $project->getId()]);
    }

    /**
     * Fotos asociadas a un proyecto, más recientes primero.
     *
     * @param LarProject $project
     * @param EntityManagerInterface $em
     * @return Image[]
     */
    private function photosOf(LarProject $project, EntityManagerInterface $em): array
    {
        return $em->getRepository(Image::class)->findForObject(LarProject::OBJECT_CLASS, $project->getId());
    }

    /**
     * Form de subida de una foto, con la acción apuntando al proyecto.
     *
     * @param LarProject $project
     * @param Image|null $image Imagen a editar (nueva por defecto).
     * @return \Symfony\Component\Form\FormInterface
     */
    private function buildPhotoForm(LarProject $project, ?Image $image = null)
    {
        return $this->createForm(ImageType::class, $image ?? new Image(), [
            'action' => $this->generateUrl('lar_photo_upload', ['id' => $project->getId()]),
            'method' => 'POST',
        ]);
    }
}
