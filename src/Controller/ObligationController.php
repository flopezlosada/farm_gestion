<?php

namespace App\Controller;

use App\Entity\Obligation;
use App\Entity\ObligationTerm;
use App\Form\ObligationTermType;
use App\Form\ObligationType;
use App\Repository\ObligationRepository;
use App\Service\Obligation\ObligationDocumentStore;
use App\Service\Obligation\ObligationWatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Registro de vencimientos: lo que la asociación tiene que mantener vigente y
 * que hoy no vigila nadie — convenios de tierra, REGA/REGEPA, pólizas,
 * concesiones de pozos, planes de prevención, el mandato de la junta.
 *
 * Modelo de permisos igual que LAR/albergue/laboral: la LECTURA la da
 * ROLE_GESTION_VENCIMIENTOS (el #[IsGranted] de clase y la regla ^/gestion de
 * security.yaml); la ESCRITURA se exige por método HTTP en access_control sobre
 * ^/gestion/vencimientos, y por eso toda mutación es POST.
 *
 * La pantalla no es el producto, es la prueba: lo que de verdad evita el
 * desastre es el correo de {@see \App\Command\SendObligationNoticesCommand}. Un
 * registro que hay que ir a mirar ya existe —el Dropbox— y no ha impedido que
 * la renovación de la junta lleve años sin archivarse.
 */
#[Route('/gestion/vencimientos')]
#[IsGranted('FEATURE_VENCIMIENTOS')]
#[IsGranted('ROLE_GESTION_VENCIMIENTOS')]
class ObligationController extends AbstractController
{
    /**
     * Listado de todo lo vigilado, lo más urgente arriba.
     *
     * @param Request              $request     Petición (filtro de archivadas).
     * @param ObligationRepository $obligations Repositorio de obligaciones.
     */
    #[Route('', name: 'obligation_index', methods: ['GET'])]
    public function index(Request $request, ObligationRepository $obligations): Response
    {
        $includeArchived = $request->query->getBoolean('archivadas');

        return $this->render('obligation/index.html.twig', [
            'obligations' => $obligations->findWatched($includeArchived),
            'include_archived' => $includeArchived,
            'counts' => $obligations->countsByState(ObligationWatch::NOTICE_DAYS),
            'notice_days' => ObligationWatch::NOTICE_DAYS,
            'thresholds' => ObligationWatch::THRESHOLDS,
        ]);
    }

    /**
     * Alta de una obligación. Pide ya la fecha del primer periodo: una ficha sin
     * fecha no vigila nada, y dejarla para luego es dejarla para nunca.
     *
     * @param Request                 $request   Petición.
     * @param EntityManagerInterface  $em        Gestor de entidades.
     * @param ObligationDocumentStore $documents Archivo de documentos.
     */
    #[Route('/nueva', name: 'obligation_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, ObligationDocumentStore $documents): Response
    {
        $obligation = new Obligation();
        $form = $this->createForm(ObligationType::class, $obligation, ['with_first_term' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $term = new ObligationTerm();
            $term->setEndsOn($form->get('firstEndsOn')->getData());
            $term->setStartsOn($form->get('firstStartsOn')->getData());
            $obligation->addTerm($term);
            $documents->attach($form->get('document')->getData(), $obligation);

            $em->persist($obligation);
            $em->persist($term);
            $em->flush();

            $this->addFlash('success', sprintf('«%s» queda bajo vigilancia.', $obligation->getName()));

            return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
        }

        return $this->render('obligation/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Ficha: qué es, cuándo caduca y el historial de renovaciones.
     *
     * @param Obligation $obligation Obligación consultada.
     */
    #[Route('/{id}', name: 'obligation_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Obligation $obligation): Response
    {
        $renewal = new ObligationTerm();
        $renewal->setEndsOn($this->suggestNextEnd($obligation));

        return $this->render('obligation/show.html.twig', [
            'obligation' => $obligation,
            'notice_days' => ObligationWatch::NOTICE_DAYS,
            'renewal_form' => $this->createForm(ObligationTermType::class, $renewal, [
                'action' => $this->generateUrl('obligation_renew', ['id' => $obligation->getId()]),
            ])->createView(),
        ]);
    }

    /**
     * Edición de los datos de la obligación. Las fechas NO se tocan aquí: se
     * anotan como renovación, que es lo que mantiene el historial y reprograma
     * el aviso.
     *
     * @param Request                 $request    Petición.
     * @param Obligation              $obligation Obligación editada.
     * @param EntityManagerInterface  $em         Gestor de entidades.
     * @param ObligationDocumentStore $documents  Archivo de documentos.
     */
    #[Route('/{id}/editar', name: 'obligation_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Obligation $obligation, EntityManagerInterface $em, ObligationDocumentStore $documents): Response
    {
        $form = $this->createForm(ObligationType::class, $obligation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $documents->attach($form->get('document')->getData(), $obligation);
            $em->flush();
            $this->addFlash('success', 'Datos actualizados.');

            return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
        }

        return $this->render('obligation/edit.html.twig', [
            'obligation' => $obligation,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Anota una renovación: añade un periodo nuevo. Con eso, el aviso del
     * siguiente vencimiento queda reprogramado solo.
     *
     * @param Request                 $request    Petición.
     * @param Obligation              $obligation Obligación renovada.
     * @param EntityManagerInterface  $em         Gestor de entidades.
     * @param ObligationDocumentStore $documents  Archivo de documentos.
     */
    #[Route('/{id}/renovar', name: 'obligation_renew', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function renew(Request $request, Obligation $obligation, EntityManagerInterface $em, ObligationDocumentStore $documents): Response
    {
        $term = new ObligationTerm();
        $form = $this->createForm(ObligationTermType::class, $term);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'No se pudo anotar la renovación: revisa las fechas.');

            return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
        }

        $obligation->addTerm($term);

        // El papel se queda en SU periodo y no se copia también a la ficha:
        // cada fila es dueña de su fichero. Con las dos apuntando al mismo,
        // sustituir el de la ficha borraría el del historial y dejaría el
        // periodo sin la prueba de que en esas fechas se estaba en regla.
        $documents->attach($form->get('document')->getData(), $term);

        $em->persist($term);
        $em->flush();

        $this->addFlash('success', sprintf(
            'Renovación anotada: vigente hasta el %s.',
            $term->getEndsOn()->format('d/m/Y')
        ));

        return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
    }

    /**
     * Borra un periodo mal anotado. No es "deshacer una renovación": es
     * corregir una fecha tecleada mal.
     *
     * @param Request                 $request    Petición (lleva el token CSRF).
     * @param Obligation              $obligation Obligación a la que pertenece.
     * @param ObligationTerm          $term       Periodo que se retira.
     * @param EntityManagerInterface  $em         Gestor de entidades.
     * @param ObligationDocumentStore $documents  Archivo de documentos.
     */
    #[Route('/{id}/periodo/{termId}/borrar', name: 'obligation_term_delete', methods: ['POST'], requirements: ['id' => '\d+', 'termId' => '\d+'])]
    public function deleteTerm(
        Request $request,
        Obligation $obligation,
        #[MapEntity(id: 'termId')] ObligationTerm $term,
        EntityManagerInterface $em,
        ObligationDocumentStore $documents,
    ): Response {
        if (!$this->isCsrfTokenValid('obligation_term_delete' . $term->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Petición caducada. Inténtalo otra vez.');

            return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
        }

        if ($term->getObligation() !== $obligation) {
            throw $this->createNotFoundException('Ese periodo no es de esta obligación.');
        }

        $documents->discard($term->getDocumentFile());

        $obligation->removeTerm($term);
        $em->remove($term);
        $em->flush();

        $this->addFlash('success', 'Periodo retirado.');

        return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
    }

    /**
     * Abre el documento vigente de la ficha.
     *
     * Sale por aquí y no por una URL directa porque los ficheros viven fuera de
     * `public/`: un convenio con DNIs e IBAN no puede quedar a un clic de
     * cualquiera que acierte la dirección. El permiso de lectura de la sección
     * —el #[IsGranted] de clase— es el que abre la puerta.
     *
     * @param Obligation              $obligation Ficha consultada.
     * @param ObligationDocumentStore $documents  Archivo de documentos.
     */
    #[Route('/{id}/documento', name: 'obligation_document', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function document(Obligation $obligation, ObligationDocumentStore $documents): Response
    {
        return $this->serve($obligation->getDocumentFile(), $documents);
    }

    /**
     * Quita el documento de la ficha. El del historial no se toca.
     *
     * @param Request                 $request    Petición (lleva el token CSRF).
     * @param Obligation              $obligation Ficha afectada.
     * @param EntityManagerInterface  $em         Gestor de entidades.
     * @param ObligationDocumentStore $documents  Archivo de documentos.
     */
    #[Route('/{id}/documento/borrar', name: 'obligation_document_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteDocument(
        Request $request,
        Obligation $obligation,
        EntityManagerInterface $em,
        ObligationDocumentStore $documents,
    ): Response {
        if (!$this->isCsrfTokenValid('obligation_document_delete' . $obligation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Petición caducada. Inténtalo otra vez.');

            return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
        }

        $documents->discard($obligation->getDocumentFile());
        $obligation->setDocumentFile(null);
        $em->flush();

        $this->addFlash('success', 'Documento retirado del archivo.');

        return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
    }

    /**
     * Abre el documento firmado de un periodo del historial.
     *
     * @param Obligation              $obligation Ficha a la que pertenece.
     * @param ObligationTerm          $term       Periodo consultado.
     * @param ObligationDocumentStore $documents  Archivo de documentos.
     */
    #[Route('/{id}/periodo/{termId}/documento', name: 'obligation_term_document', methods: ['GET'], requirements: ['id' => '\d+', 'termId' => '\d+'])]
    public function termDocument(
        Obligation $obligation,
        #[MapEntity(id: 'termId')] ObligationTerm $term,
        ObligationDocumentStore $documents,
    ): Response {
        if ($term->getObligation() !== $obligation) {
            throw $this->createNotFoundException('Ese periodo no es de esta obligación.');
        }

        return $this->serve($term->getDocumentFile(), $documents);
    }

    /**
     * Sube —o sustituye— el documento firmado de un periodo ya anotado.
     *
     * Hace falta porque el papel casi nunca está a mano el día que se anota la
     * fecha: se anota lo que se sabe y el escaneado llega después. Sin esto, la
     * única forma de archivarlo sería borrar el periodo y volver a crearlo, que
     * es pedir que se pierda el apunte y, si era el vigente, la vigilancia.
     *
     * @param Request                 $request    Petición (token CSRF y fichero).
     * @param Obligation              $obligation Ficha a la que pertenece.
     * @param ObligationTerm          $term       Periodo al que se engancha.
     * @param EntityManagerInterface  $em         Gestor de entidades.
     * @param ObligationDocumentStore $documents  Archivo de documentos.
     * @param ValidatorInterface      $validator  Validador del fichero subido.
     */
    #[Route('/{id}/periodo/{termId}/documento', name: 'obligation_term_document_upload', methods: ['POST'], requirements: ['id' => '\d+', 'termId' => '\d+'])]
    public function uploadTermDocument(
        Request $request,
        Obligation $obligation,
        #[MapEntity(id: 'termId')] ObligationTerm $term,
        EntityManagerInterface $em,
        ObligationDocumentStore $documents,
        ValidatorInterface $validator,
    ): Response {
        $back = $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);

        // PHP vacía $_POST y $_FILES enteros cuando la subida pasa de
        // post_max_size, así que una petición sin NADA dentro no es un token
        // caducado: es un fichero demasiado grande para el servidor. Sin este
        // caso, el aviso diría lo que no es y nadie entendería qué pasó.
        if ($request->request->count() === 0 && $request->files->count() === 0) {
            $this->addFlash('error', 'El servidor rechazó el fichero por tamaño antes de recibirlo. Vuelve a escanearlo con menos calidad.');

            return $back;
        }

        if (!$this->isCsrfTokenValid('obligation_term_document' . $term->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Petición caducada. Inténtalo otra vez.');

            return $back;
        }

        if ($term->getObligation() !== $obligation) {
            throw $this->createNotFoundException('Ese periodo no es de esta obligación.');
        }

        $upload = $request->files->get('document');

        if (!$upload instanceof UploadedFile) {
            $this->addFlash('error', 'No llegó ningún fichero: elige el documento antes de subirlo.');

            return $back;
        }

        $errors = $validator->validate($upload, ObligationDocumentStore::constraint());

        if (count($errors) > 0) {
            $this->addFlash('error', (string) $errors->get(0)->getMessage());

            return $back;
        }

        $documents->attach($upload, $term);
        $em->flush();

        $this->addFlash('success', sprintf(
            'Documento archivado en el periodo que acaba el %s.',
            $term->getEndsOn()->format('d/m/Y')
        ));

        return $back;
    }

    /**
     * Devuelve el fichero del archivo, o un 404 honesto si ya no está.
     *
     * Los PDFs y los escaneados se abren en el navegador (`inline`) porque lo
     * que se quiere casi siempre es mirar el documento, no bajarlo; el resto se
     * descarga. `nosniff` evita que el navegador reinterprete el contenido por
     * su cuenta: lo que se sirve es lo que dice la cabecera.
     *
     * @param string|null             $name      Nombre guardado en la fila.
     * @param ObligationDocumentStore $documents Archivo de documentos.
     */
    private function serve(?string $name, ObligationDocumentStore $documents): Response
    {
        $path = $documents->pathTo($name);

        if ($path === null) {
            throw $this->createNotFoundException('Ese documento no está en el archivo.');
        }

        $response = new BinaryFileResponse($path);
        $mime = $response->getFile()->getMimeType() ?? 'application/octet-stream';

        $response->setContentDisposition(
            $mime === 'application/pdf' || str_starts_with($mime, 'image/')
                ? ResponseHeaderBag::DISPOSITION_INLINE
                : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($path)
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Archiva o devuelve a la vigilancia. No se borra nunca: el historial de
     * periodos es lo que contesta "¿desde cuándo no tenemos esto?".
     *
     * @param Request                $request    Petición (lleva el token CSRF).
     * @param Obligation             $obligation Obligación afectada.
     * @param EntityManagerInterface $em         Gestor de entidades.
     */
    #[Route('/{id}/archivar', name: 'obligation_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(Request $request, Obligation $obligation, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('obligation_archive' . $obligation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Petición caducada. Inténtalo otra vez.');

            return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
        }

        $obligation->setArchived(!$obligation->isArchived());
        $em->flush();

        $this->addFlash('success', $obligation->isArchived()
            ? 'Archivada: deja de vigilarse y de avisar.'
            : 'Vuelve a estar bajo vigilancia.');

        return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
    }

    /**
     * Fecha de fin que se propone al anotar una renovación: un año más que la
     * vigente, que es el caso con diferencia más común (pólizas, planes
     * anuales, tasas). Es una sugerencia editable, no una regla.
     *
     * @param Obligation $obligation Obligación que se renueva.
     */
    private function suggestNextEnd(Obligation $obligation): \DateTimeImmutable
    {
        $current = $obligation->expiresOn();

        return $current !== null
            ? $current->modify('+1 year')
            : new \DateTimeImmutable('today +1 year');
    }
}
