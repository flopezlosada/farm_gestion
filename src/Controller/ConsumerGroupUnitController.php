<?php

namespace App\Controller;

use App\Entity\ConsumerGroupUnit;
use App\Form\ConsumerGroupUnitType;
use App\Repository\ConsumerGroupUnitRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD mínimo de unidades de venta del grupo de consumo. Entidad propia y
 * GLOBAL (no por productor): así "kg" es siempre la misma fila y se puede
 * comparar/sumar entre productos distintos. Calcado de
 * {@see ConsumerGroupCategoryController}.
 */
#[Route('/gestion/consumer-group/units')]
#[IsGranted('FEATURE_GRUPO_CONSUMO')]
#[IsGranted('ROLE_GESTION_GRUPO_CONSUMO')]
class ConsumerGroupUnitController extends AbstractController
{
    /**
     * Listado de unidades + formulario de alta en la misma pantalla.
     */
    #[Route('/', name: 'consumer_group_unit_index', methods: ['GET', 'POST'])]
    public function index(Request $request, ConsumerGroupUnitRepository $units, EntityManagerInterface $em): Response
    {
        $unit = new ConsumerGroupUnit();
        $form = $this->createForm(ConsumerGroupUnitType::class, $unit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($unit);
            $em->flush();
            $this->addFlash('success', 'Unidad creada.');

            return $this->redirectToRoute('consumer_group_unit_index');
        }

        return $this->render('consumer_group_unit/index.html.twig', [
            'units' => $units->findAllOrdered(),
            'form'  => $form->createView(),
        ]);
    }

    /**
     * Editar una unidad.
     */
    #[Route('/{id}/edit', name: 'consumer_group_unit_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ConsumerGroupUnit $unit, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ConsumerGroupUnitType::class, $unit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Unidad actualizada.');

            return $this->redirectToRoute('consumer_group_unit_index');
        }

        return $this->render('consumer_group_unit/edit.html.twig', [
            'unit' => $unit,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Borrar una unidad. RESTRICT: si algún producto la usa, no se deja borrar
     * (a diferencia de la categoría, aquí no hay "sin unidad" razonable).
     */
    #[Route('/{id}/delete', name: 'consumer_group_unit_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ConsumerGroupUnit $unit, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('consumer_group_unit_delete_'.$unit->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_unit_index');
        }

        try {
            $em->remove($unit);
            $em->flush();
            $this->addFlash('success', 'Unidad borrada.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('warning', 'No se pudo borrar: algún producto la usa. Márcala inactiva en su lugar.');
        }

        return $this->redirectToRoute('consumer_group_unit_index');
    }
}
