<?php

namespace App\Controller;

use App\Entity\HostingCapacity;
use App\Form\HostingCapacityType;
use App\Repository\HostingCapacityRepository;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Calendario de aforo del albergue: el aforo OPERATIVO de cada mes
 * ({@see HostingCapacity}). Un mes sin fila hereda el aforo físico de referencia
 * ({@see AppSettings::HOSTING_PHYSICAL_CAPACITY}); editarlo crea su fila.
 *
 * Módulo albergue, Fase 1 (2026-06-17).
 */
#[Route('/gestion/albergue/capacity')]
#[IsGranted('ROLE_GESTION_ALBERGUE')]
class HostingCapacityController extends AbstractController
{
    /**
     * Vista anual del aforo: los 12 meses del año con su estado (abierto/cerrado,
     * plazas) o "sin configurar" cuando heredan el aforo físico.
     *
     * @param Request $request
     * @param HostingCapacityRepository $repository
     * @param AppSettings $settings
     * @return Response
     */
    #[Route('/', name: 'hosting_capacity_index', methods: ['GET'])]
    public function index(
        Request $request,
        HostingCapacityRepository $repository,
        AppSettings $settings,
    ): Response {
        $year = (int) $request->query->get('year', (new \DateTimeImmutable('today'))->format('Y'));
        $rows = $repository->findKeyedByMonth($year, 1, $year, 12);

        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = $rows[sprintf('%04d-%02d', $year, $month)] ?? null;
        }

        return $this->render('hosting_capacity/index.html.twig', [
            'year' => $year,
            'months' => $months,
            'physical_capacity' => $settings->getInt(AppSettings::HOSTING_PHYSICAL_CAPACITY),
        ]);
    }

    /**
     * Fija el aforo FÍSICO de la casa (nº de camas) desde la pantalla de aforo.
     * Es el ancla del calendario: las plazas de cada mes no pueden superarlo.
     *
     * @param Request $request
     * @param AppSettings $settings
     * @return Response
     */
    #[Route('/physical', name: 'hosting_capacity_set_physical', methods: ['POST'])]
    public function setPhysical(Request $request, AppSettings $settings): Response
    {
        $year = (int) $request->request->get('year', (new \DateTimeImmutable('today'))->format('Y'));

        if ($this->isCsrfTokenValid('hosting_physical', (string) $request->request->get('_token'))) {
            $settings->setInt(AppSettings::HOSTING_PHYSICAL_CAPACITY, (int) $request->request->get('physical_capacity'));
            $this->addFlash('success', 'Camas de la casa actualizadas.');
        }

        return $this->redirectToRoute('hosting_capacity_index', ['year' => $year]);
    }

    /**
     * Edita el aforo de un mes concreto. Si el mes no tiene fila aún, se crea
     * una partiendo del aforo físico de referencia.
     *
     * @param Request $request
     * @param int $year
     * @param int $month
     * @param HostingCapacityRepository $repository
     * @param AppSettings $settings
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/{year}/{month}/edit', name: 'hosting_capacity_edit', methods: ['GET', 'POST'], requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'])]
    public function edit(
        Request $request,
        int $year,
        int $month,
        HostingCapacityRepository $repository,
        AppSettings $settings,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($month < 1 || $month > 12) {
            throw $this->createNotFoundException('Mes fuera de rango.');
        }

        $capacity = $repository->findForYearMonth($year, $month);
        $isNew = $capacity === null;
        if ($isNew) {
            $capacity = (new HostingCapacity())
                ->setYear($year)
                ->setMonth($month)
                ->setOpen(true)
                ->setCapacity($settings->getInt(AppSettings::HOSTING_PHYSICAL_CAPACITY));
        }

        $physical = $settings->getInt(AppSettings::HOSTING_PHYSICAL_CAPACITY);

        $form = $this->createForm(HostingCapacityType::class, $capacity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // El operativo del mes nunca puede superar las camas de la casa.
            if ($capacity->getCapacity() > $physical) {
                $form->addError(new FormError(sprintf(
                    'Las plazas (%d) no pueden superar las camas de la casa (%d). Sube primero el aforo físico.',
                    $capacity->getCapacity(),
                    $physical,
                )));
            } else {
                if ($isNew) {
                    $entityManager->persist($capacity);
                }
                $entityManager->flush();
                $this->addFlash('success', 'Aforo del mes guardado.');

                return $this->redirectToRoute('hosting_capacity_index', ['year' => $year]);
            }
        }

        return $this->render('hosting_capacity/edit.html.twig', [
            'capacity' => $capacity,
            'year' => $year,
            'month' => $month,
            'physical_capacity' => $physical,
            'form' => $form->createView(),
        ]);
    }
}
