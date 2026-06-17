<?php

namespace App\Controller;

use App\Entity\Helper;
use App\Entity\InternshipDetail;
use App\Entity\Stay;
use App\Form\InternshipDetailType;
use App\Form\StayType;
use App\Service\Hosting\HostingAvailabilityChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestión de las estancias ({@see Stay}) de los voluntarios de acogida. Siempre
 * cuelgan de un {@see Helper}: se crean desde su ficha y vuelven a ella.
 *
 * Al guardar una estancia CONFIRMADA se aplica el guard de aforo
 * ({@see HostingAvailabilityChecker}): si algún día del rango se pasaría de
 * plazas, no se persiste y se devuelve el error al formulario.
 *
 * Módulo albergue, Fase 1 (2026-06-17).
 */
#[Route('/gestion/albergue/stays')]
#[IsGranted('ROLE_GESTION_ALBERGUE')]
class StayController extends AbstractController
{
    /**
     * Nueva estancia para un voluntario.
     *
     * @param Request $request
     * @param Helper $helper
     * @param HostingAvailabilityChecker $availability
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/new/{helperId}', name: 'stay_new', methods: ['GET', 'POST'], requirements: ['helperId' => '\d+'])]
    public function new(
        Request $request,
        #[MapEntity(id: 'helperId')] Helper $helper,
        HostingAvailabilityChecker $availability,
        EntityManagerInterface $entityManager,
    ): Response {
        $stay = (new Stay())->setHelper($helper);
        $form = $this->createForm(StayType::class, $stay);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->fitsOrError($stay, $availability, $form)) {
            $entityManager->persist($stay);
            $entityManager->flush();
            $this->addFlash('success', 'Estancia creada.');

            return $this->redirectToRoute('helper_show', ['id' => $helper->getId()]);
        }

        return $this->render('stay/new.html.twig', [
            'helper' => $helper,
            'stay' => $stay,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Editar una estancia existente.
     *
     * @param Request $request
     * @param Stay $stay
     * @param HostingAvailabilityChecker $availability
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/edit', name: 'stay_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        Request $request,
        Stay $stay,
        HostingAvailabilityChecker $availability,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(StayType::class, $stay);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->fitsOrError($stay, $availability, $form)) {
            $entityManager->flush();
            $this->addFlash('success', 'Estancia actualizada.');

            return $this->redirectToRoute('helper_show', ['id' => $stay->getHelper()->getId()]);
        }

        return $this->render('stay/edit.html.twig', [
            'helper' => $stay->getHelper(),
            'stay' => $stay,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Edita (o crea al vuelo) los datos de prácticas de una estancia. Sólo tiene
     * sentido para estancias de prácticas; para el resto simplemente no se usa.
     *
     * @param Request $request
     * @param Stay $stay
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}/internship', name: 'stay_internship', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editInternship(Request $request, Stay $stay, EntityManagerInterface $entityManager): Response
    {
        $detail = $stay->getInternshipDetail();
        $isNew = $detail === null;
        if ($isNew) {
            $detail = (new InternshipDetail())->setStay($stay);
        }

        $form = $this->createForm(InternshipDetailType::class, $detail);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $entityManager->persist($detail);
            }
            $entityManager->flush();
            $this->addFlash('success', 'Datos de prácticas guardados.');

            return $this->redirectToRoute('helper_show', ['id' => $stay->getHelper()->getId()]);
        }

        return $this->render('stay/internship.html.twig', [
            'helper' => $stay->getHelper(),
            'stay' => $stay,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Borra una estancia y vuelve a la ficha de su voluntario.
     *
     * @param Request $request
     * @param Stay $stay
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{id}', name: 'stay_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Stay $stay, EntityManagerInterface $entityManager): Response
    {
        $helperId = $stay->getHelper()->getId();

        if ($this->isCsrfTokenValid('delete' . $stay->getId(), (string) $request->request->get('_token'))) {
            $entityManager->remove($stay);
            $entityManager->flush();
            $this->addFlash('success', 'Estancia borrada.');
        }

        return $this->redirectToRoute('helper_show', ['id' => $helperId]);
    }

    /**
     * Aplica el guard de aforo: si la estancia cabe (o no ocupa plaza), devuelve
     * true; si no, adjunta el error al formulario con los días en conflicto y
     * devuelve false para que el caller no persista.
     *
     * @param Stay $stay
     * @param HostingAvailabilityChecker $availability
     * @param FormInterface $form
     * @return bool
     */
    private function fitsOrError(Stay $stay, HostingAvailabilityChecker $availability, FormInterface $form): bool
    {
        $violations = $availability->violationsFor($stay);
        if ($violations === []) {
            return true;
        }

        $days = implode(', ', array_map(fn (\DateTimeImmutable $d) => $d->format('d/m/Y'), $violations));
        $form->addError(new FormError(sprintf(
            'No cabe: se superaría el aforo del albergue el/los día(s) %s. Ajusta las fechas, sube el aforo de ese mes o deja la estancia como solicitada.',
            $days,
        )));

        return false;
    }
}
