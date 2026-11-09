<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\IssuedInvoice;
use App\Entity\Remittance;
use App\Entity\User;
use App\Repository\BudgetCategoryRepository;
use App\Repository\RemittanceRepository;
use App\Service\Accounting\Closing\ClosedPeriods;
use App\Service\Accounting\Remittance\RemittanceCredits;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Anota solo el abono de una remesa enviada (`ab.rem.<ref>`) con la partida de lo que
 * cobra la remesa: cuotas de cesta o cuota de socix. Por el concepto no se puede: el
 * mismo «ab.rem» ha ido a Cuotas, Inscripciones y Mantenimiento, y el historial no
 * decide.
 *
 * Los lotes de la remesa son los abonos del día que más se acerca a lo pedido
 * ({@see RemittanceCredits::pickLines()}). Sólo se anota el MAYOR de ese día, y sólo si
 * no pasa de lo pedido: los pequeños que salen el mismo día no se sabe aún si son de
 * esta remesa o de otra que no está en la app (las inscripciones), y los decide una
 * persona. Tampoco en un trimestre entregado.
 *
 * Y sólo si ese abono es el grueso de lo pedido (al menos la mitad): un cobro suelto
 * anterior al abono de verdad (97,00 el 4 de agosto, con la remesa el 18) es lo único
 * que se ve si el extracto acaba antes de que entre la remesa, y no es la remesa. Los
 * recibos que el banco rechaza son unos pocos: el abono real ronda el 95-99 % de lo
 * pedido.
 */
class RemittanceCreditBooker
{
    /** La partida de lo que cobra cada tipo de remesa, por grupo y nombre. */
    private const CATEGORY_BY_KIND = [
        Remittance::KIND_BASKET => ['INGRESOS', 'Cuotas de socixs'],
        Remittance::KIND_MEMBERSHIP => ['INGRESOS', 'Cuota de mantenimiento'],
    ];

    /** Lo menos que tiene que traer el abono, en partes de lo pedido, para ser la remesa. */
    private const MIN_SHARE_OF_ASKED = 0.5;

    /** @var array<string, BudgetCategory|null> La partida de cada tipo, buscada una vez. */
    private array $categoryByKind = [];

    public function __construct(
        private readonly RemittanceRepository $remittances,
        private readonly BudgetCategoryRepository $categories,
        private readonly ClosedPeriods $closed,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Anota y liga el abono mayor de cada remesa enviada. No guarda: lo hace quien llama.
     *
     * @param list<BankStatementLine> $lines Movimientos sin apunte.
     * @param User|null               $by    Quien sube o vuelve a cruzar el extracto.
     *
     * @return list<AccountEntry>
     */
    public function bookSafe(array $lines, ?User $by): array
    {
        $credits = array_values(array_filter($lines, fn (BankStatementLine $l): bool => $l->getEntry() === null
            && $l->getAmountCents() > 0
            && $this->closed->closingDeliveryFor($l->getOperationDate()) === null));
        if ($credits === []) {
            return [];
        }
        $booked = [];
        foreach ($this->remittances->findAllNewestFirst() as $remittance) {
            $category = $this->categoryFor($remittance);
            $line = $category !== null ? self::mainLot($remittance, $credits) : null;
            if ($line === null || $line->getEntry() !== null) {
                continue;
            }
            $entry = BankLineEntryDraft::fromLine(new AccountEntry(), $line)
                ->setCategory($category)
                ->setCreatedBy($by)
                ->markAutoBooked();
            $this->em->persist($entry);
            $line->linkTo($entry);
            $booked[] = $entry;
        }

        return $booked;
    }

    /**
     * El abono mayor del día de los lotes de la remesa, si es uno solo, no pasa de lo
     * pedido y trae al menos la mitad. Null si no hay o hay dos iguales. Puro.
     *
     * @param list<BankStatementLine> $credits Abonos sin apunte.
     */
    public static function mainLot(Remittance $remittance, array $credits): ?BankStatementLine
    {
        if (!$remittance->isSent()) {
            return null;
        }
        [$from, $to] = RemittanceCredits::range($remittance);
        $lots = RemittanceCredits::pickLines($credits, $from, $to, $remittance);
        usort($lots, static fn (BankStatementLine $a, BankStatementLine $b): int => $b->getAmountCents() <=> $a->getAmountCents());
        $main = $lots[0] ?? null;
        $tied = isset($lots[1]) && $lots[1]->getAmountCents() === $main?->getAmountCents();
        $asked = IssuedInvoice::toCents($remittance->getTotal());

        return $main !== null && !$tied
            && $main->getAmountCents() <= $asked
            && $main->getAmountCents() >= $asked * self::MIN_SHARE_OF_ASKED
            ? $main : null;
    }

    /**
     * La partida del tipo de remesa, si existe, está viva y no es de traspasos. Una
     * consulta por tipo, no por remesa.
     */
    private function categoryFor(Remittance $remittance): ?BudgetCategory
    {
        $kind = $remittance->getKind();
        if (!\array_key_exists($kind, $this->categoryByKind)) {
            [$group, $name] = self::CATEGORY_BY_KIND[$kind] ?? [null, null];
            $category = $group !== null ? $this->categories->findOneByGroupAndName($group, $name) : null;
            $this->categoryByKind[$kind] = $category !== null && $category->isActive() && !$category->isTransfer() ? $category : null;
        }

        return $this->categoryByKind[$kind];
    }
}
