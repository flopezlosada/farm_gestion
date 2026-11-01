<?php

namespace App\Controller;

use App\Entity\Remittance;
use App\Repository\RemittanceRepository;
use App\Service\Partner\Fee\FeeStatement;
use App\Service\Partner\Fee\MonthlyFeeCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Las cuotas de un mes: lo que paga cada persona (sus cestas por las semanas que las
 * tuvo, más sus ajustes), lo que falta para poder cobrarlo y el total. Es lo que
 * saldría en la remesa de ese mes.
 *
 * Sólo lectura: se cambia en las tarifas, en la cesta o en los ajustes de cada ficha.
 */
#[Route('/gestion/partner/fees')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class MonthlyFeeController extends AbstractController
{
    /** Pestañas: todas, o sólo las que aún no se pueden cobrar. */
    private const FILTERS = ['all', 'problems'];

    #[Route('', name: 'monthly_fee_index', methods: ['GET'])]
    public function index(Request $request, MonthlyFeeCalculator $calculator, RemittanceRepository $remittances): Response
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->query->get('month')) ?: new \DateTimeImmutable('first day of this month');
        $filter = \in_array($request->query->get('show'), self::FILTERS, true) ? $request->query->get('show') : 'all';
        $fees = $calculator->forMonth($month);
        $statements = $fees->getStatements();
        $problems = array_values(array_filter($statements, static fn (FeeStatement $s): bool => $s->getProblems() !== []));

        return $this->render('monthly_fee/index.html.twig', [
            'fees' => $fees,
            'statements' => $filter === 'problems' ? $problems : $statements,
            'counts' => ['all' => \count($statements), 'problems' => \count($problems), 'paying' => \count(array_filter($statements, static fn (FeeStatement $s): bool => !$s->isFree()))],
            'filter' => $filter,
            // La remesa de cuotas de este mes, si ya se preparó: para ir a ella en vez de prepararla otra vez.
            'remittance' => $remittances->findOneBy(['kind' => Remittance::KIND_BASKET, 'period' => $fees->month]),
        ]);
    }
}
