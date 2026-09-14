<?php

namespace App\Service\Accounting;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mueve dinero de una cuenta propia a otra.
 *
 * Existe para que un traspaso no se pueda quedar a medias. Son dos apuntes —uno que
 * sale y otro que entra— y anotarlos sueltos es fácil de empezar y fácil de olvidar:
 * en el libro de 2026 los traspasos descuadran 115 € porque en dos casos las dos
 * mitades llevan importes distintos. Aquí se crean juntos, con el mismo importe y
 * enlazados, o no se crea ninguno.
 */
class TransferRecorder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Crea las dos mitades de un traspaso y devuelve la que sale.
     *
     * @param string $amount importe en positivo; el signo lo pone cada mitad
     *
     * @throws \InvalidArgumentException si el origen y el destino son la misma cuenta
     *                                   o el importe no es positivo
     */
    public function record(
        FinancialAccount $from,
        FinancialAccount $to,
        string $amount,
        \DateTimeInterface $date,
        string $concept = '',
        ?User $author = null,
    ): AccountEntry {
        if ($from === $to) {
            throw new \InvalidArgumentException('El origen y el destino no pueden ser la misma cuenta.');
        }
        $value = abs((float) $amount);
        if ($value < 0.005) {
            throw new \InvalidArgumentException('El importe del traspaso tiene que ser mayor que cero.');
        }

        $category = $this->transferCategory();
        $money = number_format($value, 2, '.', '');
        $text = $concept !== '' ? $concept : sprintf('Traspaso de %s a %s', $from->getName(), $to->getName());

        $out = $this->newEntry($from, $category, $date, $text, '-'.$money, $author);
        $in = $this->newEntry($to, $category, $date, $text, $money, $author);
        $out->pairWith($in);

        $this->em->persist($out);
        $this->em->persist($in);

        return $out;
    }

    /**
     * Borra las DOS mitades de un traspaso. Borrar sólo una dejaría el saldo de una
     * cuenta mal, que es precisamente lo que este servicio existe para impedir.
     */
    public function remove(AccountEntry $entry): void
    {
        $peer = $entry->getTransferPeer();
        $entry->pairWith(null);
        if ($peer !== null) {
            $peer->pairWith(null);
            $this->em->remove($peer);
        }
        $this->em->remove($entry);
    }

    /**
     * La partida de traspasos. Es única por construcción: su grupo es el del tipo
     * TRANSFER, que sólo tiene esa.
     *
     * @throws \RuntimeException si el catálogo no la tiene (base sin sembrar)
     */
    private function transferCategory(): BudgetCategory
    {
        $category = $this->em->createQueryBuilder()
            ->select('c')
            ->from(BudgetCategory::class, 'c')
            ->innerJoin('c.group', 'g')
            ->andWhere('g.kind = :kind')->setParameter('kind', BudgetCategoryGroup::KIND_TRANSFER)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$category instanceof BudgetCategory) {
            throw new \RuntimeException('No hay ninguna partida de traspasos en el catálogo.');
        }

        return $category;
    }

    /** Una mitad del traspaso. */
    private function newEntry(
        FinancialAccount $account,
        BudgetCategory $category,
        \DateTimeInterface $date,
        string $concept,
        string $amount,
        ?User $author,
    ): AccountEntry {
        return (new AccountEntry())
            ->setAccount($account)
            ->setCategory($category)
            ->setDate($date)
            ->setConcept($concept)
            ->setAmount($amount)
            ->setCreatedBy($author);
    }
}
