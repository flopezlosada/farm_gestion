<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\IssuedInvoice;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\Remittance;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lo que deberían pagar las familias un mes según sus cestas: la referencia con la
 * que {@see RemittanceReview} contrasta los recibos. Sólo es eso, una referencia: la
 * cuota de verdad la decide la remesa.
 */
class BasketFeeExpectations
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Lo que suman las cestas activas en el mes, por familia: verdura, huevos y transporte de
     * cada cesta, a cargo de quien la paga (el pagador si lo hay, si no quien la
     * recibe). Una sola consulta para todas.
     *
     * @return array<int, array{partner: Partner, cents: int}>
     */
    public function forMonth(\DateTimeInterface $period): array
    {
        $start = Remittance::firstOfMonth($period);

        /** @var list<PartnerBasketShare> $shares */
        $shares = $this->em->createQueryBuilder()
            ->select('s', 'p', 'payer', 'head', 'payerHead')
            ->from(PartnerBasketShare::class, 's')
            ->join('s.partner', 'p')
            ->leftJoin('p.parent', 'head')
            ->leftJoin('s.payer_partner', 'payer')
            ->leftJoin('payer.parent', 'payerHead')
            ->andWhere('s.is_active = true')
            ->andWhere('s.start_date IS NULL OR s.start_date <= :end')
            ->andWhere('s.end_date IS NULL OR s.end_date >= :start')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $start->modify('last day of this month')->format('Y-m-d'))
            ->getQuery()
            ->getResult();

        $fees = [];
        foreach ($shares as $share) {
            $head = $share->getEffectivePayer()->getFamilyHead();
            $cents = array_sum(array_map(
                static fn (?string $price): int => IssuedInvoice::toCents($price ?? '0'),
                [$share->getMonthPrice(), $share->getEggMonthPrice(), $share->getTransportPrice()],
            ));
            $fees[$head->getId()] = ['partner' => $head, 'cents' => ($fees[$head->getId()]['cents'] ?? 0) + $cents];
        }

        return $fees;
    }

}
