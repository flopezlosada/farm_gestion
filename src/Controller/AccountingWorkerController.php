<?php

namespace App\Controller;

use App\Entity\Worker;
use App\Entity\WorkerSalary;
use App\Form\WorkerSalaryType;
use App\Repository\WorkerRepository;
use App\Repository\WorkerSalaryRepository;
use App\Service\Accounting\MonthNames;
use App\Service\Accounting\PayrollCost;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lo que cuesta cada trabajador: sus tramos de sueldo y, con ellos, lo que toca pagar
 * cada mes del año. Es la base para prever los sueldos en las simulaciones.
 *
 * Sólo ROLE_ADMIN, aunque el resto de la contabilidad lo vea más gente: son sueldos.
 * Las personas son las fichas del registro de jornada; aquí sólo se les pone precio.
 */
#[Route('/gestion/accounting/workers')]
#[IsGranted('FEATURE_CONTABILIDAD')]
#[IsGranted('ROLE_ADMIN')]
class AccountingWorkerController extends AbstractController
{
    /**
     * Cada trabajador con sus tramos y la rejilla del año: bruto y Seguridad Social de
     * la empresa mes a mes.
     */
    #[Route('', name: 'accounting_workers', methods: ['GET'])]
    public function index(
        Request $request,
        WorkerRepository $workers,
        WorkerSalaryRepository $salaries,
        PayrollCost $payroll,
    ): Response {
        $year = $request->query->getInt('year');
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }
        $costs = $payroll->forYear($salaries->findTouchingYear($year), $year);

        $totals = array_fill(1, 12, 0.0);
        foreach ($costs as $cost) {
            foreach ($totals as $m => $total) {
                $totals[$m] = $total + $cost['gross'][$m] + $cost['ss'][$m];
            }
        }

        return $this->render('accounting/workers.html.twig', [
            'workers' => $workers->findBy([], ['active' => 'DESC', 'name' => 'ASC']),
            'salaries' => $salaries->findAllByWorker(),
            'costs' => $costs,
            'totals' => $totals,
            'year' => $year,
            'months' => MonthNames::SHORT,
        ]);
    }

    /**
     * Ponerle un sueldo nuevo: el primero o el que sustituye al de antes. Si los dos
     * siguen abiertos, el de antes se cierra el día anterior.
     */
    #[Route('/{id}/salary/new', name: 'accounting_worker_salary_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function salaryNew(
        Request $request,
        Worker $worker,
        WorkerSalaryRepository $salaries,
        EntityManagerInterface $em,
    ): Response {
        $existing = $salaries->findForWorker($worker);
        $previous = $existing === [] ? null : $existing[array_key_last($existing)];

        // Lo normal es cambiar el sueldo: arranca con lo de antes para tocar sólo lo que cambia.
        $salary = (new WorkerSalary())->setWorker($worker);
        if ($previous !== null) {
            $salary->setAnnualGross($previous->getAnnualGross())
                ->setPayments($previous->getPayments())
                ->setEmployerSsRate($previous->getEmployerSsRate());
        }

        $form = $this->createForm(WorkerSalaryType::class, $salary);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Sólo un sueldo que sigue sustituye al de antes. Uno con fecha de fin (una
            // reducción temporal) dejaría a la persona sin sueldo al acabar: ése choca
            // con el abierto y se decide a mano.
            if ($previous !== null && $previous->getValidTo() === null && $salary->getValidTo() === null
                && $salary->getValidFrom() > $previous->getValidFrom()) {
                $previous->setValidTo($salary->getValidFrom()->modify('-1 day'));
            }
            if ($this->rejectOverlap($form, $salary, $existing)) {
                $em->persist($salary);
                $em->flush();
                $this->addFlash('success', sprintf('Sueldo de %s guardado.', $worker->getName()));

                return $this->redirectToRoute('accounting_workers');
            }
        }

        return $this->render('accounting/worker_salary_form.html.twig', [
            'form' => $form->createView(),
            'worker' => $worker,
            'salary' => null,
        ]);
    }

    /**
     * Corregir un tramo: una errata en el bruto, o ponerle fecha de fin.
     */
    #[Route('/salary/{id}/edit', name: 'accounting_worker_salary_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function salaryEdit(
        Request $request,
        WorkerSalary $salary,
        WorkerSalaryRepository $salaries,
        EntityManagerInterface $em,
    ): Response {
        $form = $this->createForm(WorkerSalaryType::class, $salary);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()
            && $this->rejectOverlap($form, $salary, $salaries->findForWorker($salary->getWorker()))) {
            $em->flush();
            $this->addFlash('success', 'Sueldo actualizado.');

            return $this->redirectToRoute('accounting_workers');
        }

        return $this->render('accounting/worker_salary_form.html.twig', [
            'form' => $form->createView(),
            'worker' => $salary->getWorker(),
            'salary' => $salary,
        ]);
    }

    /**
     * Un mes no puede pagarse dos veces: si el tramo pisa a otro de la misma persona,
     * lo dice en el formulario y no deja guardar.
     *
     * @param list<WorkerSalary> $others los tramos de esa persona (puede ir él mismo)
     *
     * @return bool true si se puede guardar
     */
    private function rejectOverlap(FormInterface $form, WorkerSalary $salary, array $others): bool
    {
        foreach ($others as $other) {
            if ($other !== $salary && $salary->overlaps($other)) {
                $form->get('validFrom')->addError(new FormError(sprintf(
                    'Pisa el sueldo %s. Cierra ese antes o cambia las fechas.',
                    $other->getValidTo() === null
                        ? 'que va desde el '.$other->getValidFrom()->format('d/m/Y')
                        : 'que va del '.$other->getValidFrom()->format('d/m/Y').' al '.$other->getValidTo()->format('d/m/Y'),
                )));

                return false;
            }
        }

        return true;
    }
}
