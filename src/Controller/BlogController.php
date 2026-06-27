<?php

namespace App\Controller;

use App\Entity\Blog;
use App\Form\BlogEditType;
use App\Form\BlogSecondType;
use App\Form\BlogType;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WhiteOctober\BreadcrumbsBundle\Model\Breadcrumbs;

/**
 * Blog controller.
 *
 * Las acciones públicas (frontend_index, show, show_category, latest_post)
 * NO llevan restricción de rol: sirven el blog y las recetas a visitantes
 * anónimos de csavegadejarama.org. Las acciones de administración (CRUD +
 * media) exigen ROLE_BLOG de forma explícita en cada método, porque el
 * IsGranted a nivel de clase también bloqueaba las públicas y mandaba al
 * login al entrar en /blog o /blog/category/4 (Recetas).
 */
class BlogController extends AbstractController
{
    /**
     * Id de la categoría "Recetas". Las recetas tienen su propia sección
     * (/blog/category/4) y NO deben aparecer mezcladas en el listado general
     * del blog. Mismo id hardcodeado que en el menú (base_front.html.twig).
     */
    private const RECETAS_CATEGORY_ID = 4;

    #[IsGranted('ROLE_BLOG')]
    public function index(Request $request, EntityManagerInterface $em)
    {
        // Filtro opcional por categoría desde los tabs.
        $categoryId = $request->query->getInt('category') ?: null;
        $criteria = $categoryId ? ['category' => $categoryId] : [];
        $entities = $em->getRepository(\App\Entity\Blog::class)->findBy($criteria, ['created' => 'DESC']);

        // Lista de categorías con conteo para pintar los tabs y el total.
        $categories = $em->createQuery(
            'SELECT c.id AS id, c.name AS name, COUNT(b.id) AS n
             FROM App\Entity\Category c
             LEFT JOIN App\Entity\Blog b WITH b.category = c
             GROUP BY c.id
             ORDER BY n DESC'
        )->getArrayResult();
        $totalCount = $em->createQuery('SELECT COUNT(b.id) FROM App\Entity\Blog b')->getSingleScalarResult();

        return $this->render('Blog/index.html.twig', array(
            'entities' => $entities,
            'categories' => $categories,
            'total_count' => $totalCount,
            'current_category' => $categoryId,
        ));
    }

    public function frontend_index(EntityManagerInterface $em, Request $request, PaginatorInterface $paginator, Breadcrumbs $breadcrumbs)
    {

        $q = trim((string) $request->query->get('q', ''));

        // El listado general del blog excluye las recetas: tienen su propia
        // sección. Antes se mezclaban por fecha y aparecían al paginar, dando
        // la sensación de que la página 2 del blog "llevaba a recetas".
        // Se conservan las entradas sin categoría (category IS NULL).
        $dql = "select b from App\\Entity\\Blog b
                where (b.category is null or b.category != :recetasId)";
        if ($q !== '') {
            $dql .= " and (b.title like :q or b.content like :q)";
        }
        $dql .= " order by b.created desc";

        $query = $em->createQuery($dql)
            ->setParameter('recetasId', self::RECETAS_CATEGORY_ID);
        if ($q !== '') {
            $query->setParameter('q', '%' . $q . '%');
        }

        $pagination = $paginator->paginate(
            $query,
            $request->query->get('page', 1)/*page number*/,
            5/*limit per page*/
        );

        $breadcrumbs->addItem("Home", $this->generateUrl("homepage"));
        $breadcrumbs->addItem("Blog", $this->generateUrl("frontend_blog"));


        return $this->render('Blog/frontend_index.html.twig', array(
            'entities' => $pagination,
            'categories' => $this->categoriesWithCount($em),
            'upcoming_events' => $this->upcomingEvents($em),
            'q' => $q,
        ));

    }

    /**
     * Próximos eventos públicos (Booking) para la caja del sidebar del blog,
     * encima de las categorías. Limitado a 3: la agenda completa vive en
     * /asambleas.
     *
     * @param EntityManagerInterface $em
     * @return \App\Entity\Booking[]
     */
    private function upcomingEvents(EntityManagerInterface $em): array
    {
        return $em->getRepository(\App\Entity\Booking::class)->findUpcoming(3);
    }

    /**
     * Devuelve las categorías del blog con el número de entradas de cada una,
     * para pintar el menú lateral del frontend público. Ordenadas por nombre.
     *
     * @param EntityManagerInterface $em
     * @return array<int, array{id:int, name:string, n:int}>
     */
    private function categoriesWithCount(EntityManagerInterface $em): array
    {
        return $em->createQuery(
            'SELECT c.id AS id, c.name AS name, COUNT(b.id) AS n
             FROM App\Entity\Category c
             LEFT JOIN App\Entity\Blog b WITH b.category = c
             GROUP BY c.id
             ORDER BY c.name ASC'
        )->getArrayResult();
    }

    /**
     * Creates a new Blog entity.
     *
     */
    #[IsGranted('ROLE_BLOG')]
    public function create(Request $request, EntityManagerInterface $em)
    {
        $entity = new Blog();

        $form = $this->createCreateForm($entity);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($entity);
            $em->flush();

            return $this->redirect($this->generateUrl('blog_second_step', array('id' => $entity->getId())));
        } else {
            ladybug_dump($form->getErrors());
        }
        return $this->render('Blog/new.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
        ));

    }


    /**
     * Creates a form to create a Blog entity.
     *
     * @param Blog $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Blog $entity)
    {
        $form = $this->createForm(BlogType::class, $entity, array(
            'action' => $this->generateUrl('blog_create'),
            'method' => 'POST',
        ));
        $form->add('submit', SubmitType::class, array('label' => 'Añadir'));

        return $form;
    }


    /**
     * Displays a form to create a new Blog entity.
     *
     */
    #[IsGranted('ROLE_BLOG')]
    public function new()
    {
        $entity = new Blog();

        $form = $this->createCreateForm($entity);

        return $this->render('Blog/new.html.twig', array(
            'entity' => $entity,

            'form' => $form->createView(),
        ));
    }


    #[IsGranted('ROLE_BLOG')]
    public function second($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Blog::class)->find($id);
        $galleries = $em->getRepository(\App\Entity\Blog::class)->findMedia("Gallery", $id, "blog");
        $audios = $em->getRepository(\App\Entity\Blog::class)->findMedia("Audio", $id, "blog");
        $videos = $em->getRepository(\App\Entity\Blog::class)->findMedia("Video", $id, "blog");
        $documents = $em->getRepository(\App\Entity\Blog::class)->findMedia("Document", $id, "blog");

        $form = $this->createSecondForm($entity);

        return $this->render('Blog/second.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
            'galleries' => $galleries,
            'audios' => $audios,
            'videos' => $videos,
            'documents' => $documents
        ));
    }


    private function createSecondForm(Blog $entity)
    {
        $form = $this->createForm(BlogSecondType::class, $entity, array(
            'action' => $this->generateUrl('blog_update_second', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));
        $form->add('submit', SubmitType::class, array('label' => 'Create'));

        return $form;
    }

    #[IsGranted('ROLE_BLOG')]
    public function updateSecond(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Blog::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createSecondForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->flush();

            $this->addFlash('success', 'Cambios guardados.');
            return $this->redirect($this->generateUrl('blog_edit', array('id' => $entity->getId())));
        }

        return $this->render('Blog/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }


    /**
     * Finds and displays a Blog entity.
     *
     */
    public function show($slug, Breadcrumbs $breadcrumbs, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Blog::class)->findPostsBySlug($slug);


        $breadcrumbs->addItem("Home", $this->generateUrl("homepage"));
        $breadcrumbs->addItem("Blog", $this->generateUrl("frontend_blog"));
        $breadcrumbs->addItem($entity->getTitle(), "");

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        // Contador de visitas reales: cada render del post en el blog público
        // suma una. Sin deduplicación por sesión/IP (KISS); si en el futuro
        // estorba el ruido de bots/recargas, se filtra aquí.
        $entity->incrementViews();
        $em->flush();

        $deleteForm = $this->createDeleteForm($entity->getId());

        return $this->render('Blog/show.html.twig', array(
            'post' => $entity,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing Blog entity.
     *
     */
    #[IsGranted('ROLE_BLOG')]
    public function edit($id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Blog::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $editForm = $this->createEditForm($entity);
        $deleteForm = $this->createDeleteForm($id);

        return $this->render('Blog/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Creates a form to edit a Blog entity.
     *
     * @param Blog $entity The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createEditForm(Blog $entity)
    {
        $form = $this->createForm(BlogEditType::class, $entity, array(
            'action' => $this->generateUrl('blog_update', array('id' => $entity->getId())),
            'method' => 'PUT',
        ));

        $form->add('submit', SubmitType::class, array('label' => 'Update'));

        return $form;
    }

    /**
     * Edits an existing Blog entity.
     *
     */
    #[IsGranted('ROLE_BLOG')]
    public function update(Request $request, $id, EntityManagerInterface $em)
    {
        $entity = $em->getRepository(\App\Entity\Blog::class)->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Blog entity.');
        }

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createEditForm($entity);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $entity->setModified(1);
            $em->persist($entity);
            $entity->setModified(0);
            $em->flush();

            $this->addFlash('success', 'Cambios guardados.');
            return $this->redirect($this->generateUrl('blog_edit', array('id' => $entity->getId())));
        }

        return $this->render('Blog/edit.html.twig', array(
            'entity' => $entity,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Blog entity.
     *
     */
    #[IsGranted('ROLE_BLOG')]
    public function delete(Request $request, $id, EntityManagerInterface $em)
    {
        $form = $this->createDeleteForm($id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $em->getRepository(\App\Entity\Blog::class)->find($id);

            if (!$entity) {
                throw $this->createNotFoundException('Unable to find Blog entity.');
            }

            $em->remove($entity);
            $em->flush();
        }

        return $this->redirect($this->generateUrl('blog'));
    }

    /**
     * Creates a form to delete a Blog entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('blog_delete', array('id' => $id)))
            ->setMethod('DELETE')
            ->add('submit', SubmitType::class, array('label' => 'Delete'))
            ->getForm();
    }


    #[IsGranted('ROLE_BLOG')]
    public function edition($id, $object_class, EntityManagerInterface $em)
    {
        $entity = $em->getRepository('App\Entity\\' . ucfirst($object_class))->find($id);
        $single_images = $em->getRepository(\App\Entity\Blog::class)->findMedia("Image", $id, $object_class, 1);
        $grouped_images = $em->getRepository(\App\Entity\Blog::class)->findMedia("Image", $id, $object_class, 0);
        $audios = $em->getRepository(\App\Entity\Blog::class)->findMedia("Audio", $id, $object_class);
        $videos = $em->getRepository(\App\Entity\Blog::class)->findMedia("Video", $id, $object_class);
        $documents = $em->getRepository(\App\Entity\Blog::class)->findMedia("Document", $id, $object_class);


        return $this->render('Blog/edition.html.twig', array(
            'entity' => $entity,
            'single_images' => $single_images,
            'grouped_images' => $grouped_images,
            'audios' => $audios,
            'videos' => $videos,
            'documents' => $documents,
            'object_class' => $object_class
        ));
    }


    #[IsGranted('ROLE_BLOG')]
    public function categories()
    {
    }

    public function show_category($id, Request $request, PaginatorInterface $paginator, Breadcrumbs $breadcrumbs, EntityManagerInterface $em)
    {
        $category = $em->getRepository(\App\Entity\Category::class)->find($id);

        $q = trim((string) $request->query->get('q', ''));

        $dql = "select b from App\\Entity\\Blog b where b.category = :category";
        if ($q !== '') {
            $dql .= " and (b.title like :q or b.content like :q)";
        }
        $dql .= " order by b.created desc";

        $query = $em->createQuery($dql)->setParameter('category', $category);
        if ($q !== '') {
            $query->setParameter('q', '%' . $q . '%');
        }

        $pagination = $paginator->paginate(
            $query,
            $request->query->get('page', 1)/*page number*/,
            5/*limit per page*/
        );




        $breadcrumbs->addItem("Home", $this->generateUrl("homepage"));
        $breadcrumbs->addItem("Blog", $this->generateUrl("frontend_blog"));

        return $this->render("Blog/frontend_index.html.twig", array(
            'category' => $category,
            'entities' => $pagination,
            'categories' => $this->categoriesWithCount($em),
            'upcoming_events' => $this->upcomingEvents($em),
            'q' => $q,
        ));

    }

    public function latest_post(EntityManagerInterface $em)
    {
        $entities = $em->getRepository(\App\Entity\Blog::class)->findLatest(3, 1);

        return $this->render("Blog/latest_post.html.twig", array(
            'entities' => $entities,
        ));
    }
}
