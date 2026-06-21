<?php

namespace App\Controller;

use App\Entity\Holiday;
use App\Form\HolidayType;
use App\Repository\HolidayRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Calendario laboral de festivos. Los días de aquí no cuentan como hueco del
 * registro de jornada y se marcan en los calendarios. Se gestionan a mano (≈14
 * al año): nacionales + autonómicos de Madrid + locales.
 *
 * Cuelga del área de personal (/gestion/staff): lectura con ROLE_GESTION_LABORAL
 * y escritura (POST) con ROLE_GESTION_LABORAL_EDIT, gateada por método HTTP en
 * security.yaml (access_control de ^/gestion/staff), igual que el resto del área.
 */
#[Route('/gestion/staff/holidays')]
#[IsGranted('FEATURE_LABORAL')]
#[IsGranted('ROLE_GESTION_LABORAL')]
class HolidayController extends AbstractController
{
    /**
     * Lista los festivos (próximos primero) y el formulario de alta.
     *
     * @param HolidayRepository $holidays
     * @return Response
     */
    #[Route('', name: 'staff_holidays', methods: ['GET'])]
    public function index(HolidayRepository $holidays): Response
    {
        $form = $this->createForm(HolidayType::class, new Holiday());

        return $this->render('staff/holidays.html.twig', [
            'holidays' => $holidays->findAllOrdered(),
            'form' => $form->createView(),
        ]);
    }

    /**
     * Da de alta un festivo. La fecha es única: si ya existe, se avisa sin
     * duplicar.
     *
     * @param Request                $request
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('', name: 'staff_holiday_new', methods: ['POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $holiday = new Holiday();
        $form = $this->createForm(HolidayType::class, $holiday);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Revisa la fecha y el nombre del festivo.');

            return $this->redirectToRoute('staff_holidays');
        }

        try {
            $em->persist($holiday);
            $em->flush();
            $this->addFlash('success', sprintf('Festivo «%s» añadido.', $holiday->getName()));
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', sprintf('Ya hay un festivo el %s.', $holiday->getDate()->format('d/m/Y')));
        }

        return $this->redirectToRoute('staff_holidays');
    }

    /**
     * Borra un festivo. A diferencia de los fichajes, un festivo no es un registro
     * legal: se puede corregir libremente.
     *
     * @param Request                $request
     * @param Holiday                $holiday
     * @param EntityManagerInterface $em
     * @return Response
     */
    #[Route('/{id}', name: 'staff_holiday_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Holiday $holiday, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_holiday' . $holiday->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('staff_holidays');
        }

        $em->remove($holiday);
        $em->flush();
        $this->addFlash('success', 'Festivo borrado.');

        return $this->redirectToRoute('staff_holidays');
    }
}
