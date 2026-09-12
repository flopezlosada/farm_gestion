<?php

namespace App\Controller;

use App\Entity\Obligation;
use App\Entity\ObligationTerm;
use App\Form\ObligationTermType;
use App\Form\ObligationType;
use App\Repository\ObligationRepository;
use App\Service\Obligation\ObligationWatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
     * @param Request                $request Petición.
     * @param EntityManagerInterface $em      Gestor de entidades.
     */
    #[Route('/nueva', name: 'obligation_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $obligation = new Obligation();
        $form = $this->createForm(ObligationType::class, $obligation, ['with_first_term' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $term = new ObligationTerm();
            $term->setEndsOn($form->get('firstEndsOn')->getData());
            $term->setStartsOn($form->get('firstStartsOn')->getData());
            $obligation->addTerm($term);

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
     * @param Request                $request    Petición.
     * @param Obligation             $obligation Obligación editada.
     * @param EntityManagerInterface $em         Gestor de entidades.
     */
    #[Route('/{id}/editar', name: 'obligation_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Obligation $obligation, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ObligationType::class, $obligation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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
     * @param Request                $request    Petición.
     * @param Obligation             $obligation Obligación renovada.
     * @param EntityManagerInterface $em         Gestor de entidades.
     */
    #[Route('/{id}/renovar', name: 'obligation_renew', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function renew(Request $request, Obligation $obligation, EntityManagerInterface $em): Response
    {
        $term = new ObligationTerm();
        $form = $this->createForm(ObligationTermType::class, $term);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'No se pudo anotar la renovación: revisa las fechas.');

            return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
        }

        $obligation->addTerm($term);
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
     * @param Request                $request    Petición (lleva el token CSRF).
     * @param Obligation             $obligation Obligación a la que pertenece.
     * @param ObligationTerm         $term       Periodo que se retira.
     * @param EntityManagerInterface $em         Gestor de entidades.
     */
    #[Route('/{id}/periodo/{termId}/borrar', name: 'obligation_term_delete', methods: ['POST'], requirements: ['id' => '\d+', 'termId' => '\d+'])]
    public function deleteTerm(
        Request $request,
        Obligation $obligation,
        #[\Symfony\Bridge\Doctrine\Attribute\MapEntity(id: 'termId')] ObligationTerm $term,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('obligation_term_delete' . $term->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Petición caducada. Inténtalo otra vez.');

            return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
        }

        if ($term->getObligation() !== $obligation) {
            throw $this->createNotFoundException('Ese periodo no es de esta obligación.');
        }

        $obligation->removeTerm($term);
        $em->remove($term);
        $em->flush();

        $this->addFlash('success', 'Periodo retirado.');

        return $this->redirectToRoute('obligation_show', ['id' => $obligation->getId()]);
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
