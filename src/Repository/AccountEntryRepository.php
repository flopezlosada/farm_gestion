<?php

namespace App\Repository;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Los apuntes del libro. Aquí viven las sumas de las que salen el saldo de cada
 * cuenta, el resumen del mes, el del año y el seguimiento del presupuesto: ninguna de
 * esas cifras se guarda en ningún sitio, todas se calculan sobre esta tabla.
 *
 * @extends ServiceEntityRepository<AccountEntry>
 */
class AccountEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountEntry::class);
    }

    /**
     * Saldo de una cuenta a una fecha: su saldo de apertura más todos los apuntes
     * desde esa apertura. Sin fecha, el saldo de hoy.
     */
    public function balanceFor(FinancialAccount $account, ?\DateTimeInterface $upTo = null): string
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COALESCE(SUM(e.amount), 0) AS total')
            ->andWhere('e.account = :account')->setParameter('account', $account)
            ->andWhere('e.date >= :opening')->setParameter('opening', $account->getOpeningDate());

        if ($upTo !== null) {
            $qb->andWhere('e.date <= :upTo')->setParameter('upTo', $upTo);
        }

        $moved = (string) $qb->getQuery()->getSingleScalarResult();

        return $this->money((float) $account->getOpeningBalance() + (float) $moved);
    }

    /**
     * Cuántos apuntes tiene cada cuenta: `[accountId => nº]`. Sirve para saber si una
     * cuenta ya está en uso, y por tanto si su saldo de apertura todavía se puede
     * corregir o hay que dejarlo quieto.
     *
     * @return array<int, int>
     */
    public function countByAccount(): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.account) AS accountId', 'COUNT(e.id) AS total')
            ->groupBy('e.account')
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['accountId']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Cuántos apuntes hay en cada partida: `[categoryId => nº]`. Igual que el de
     * cuentas, dice qué partidas están en uso y cuáles se pueden retirar sin ruido.
     *
     * @return array<int, int>
     */
    public function countByCategory(): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.category) AS categoryId', 'COUNT(e.id) AS total')
            ->groupBy('e.category')
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['categoryId']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Lo movido en cada partida y mes de un año: `[categoryId][month] => importe`.
     * Es la mitad «real» de la rejilla de seguimiento, y también el resumen anual.
     * Una consulta para los doce meses y todas las partidas.
     *
     * @return array<int, array<int, string>>
     */
    public function totalsByCategoryAndMonth(int $year): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.category) AS categoryId', 'MONTH(e.date) AS m', 'SUM(e.amount) AS total')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', $year.'-01-01')
            ->setParameter('to', $year.'-12-31')
            ->groupBy('e.category', 'm')
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['categoryId']][(int) $row['m']] = (string) $row['total'];
        }

        return $out;
    }

    /**
     * Lo movido en cada cuenta y mes de un año: `[accountId][month] => importe`. Con
     * el saldo de apertura de cada cuenta reconstruye el saldo a final de cada mes.
     *
     * @return array<int, array<int, string>>
     */
    public function totalsByAccountAndMonth(int $year): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.account) AS accountId', 'MONTH(e.date) AS m', 'SUM(e.amount) AS total')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', $year.'-01-01')
            ->setParameter('to', $year.'-12-31')
            ->groupBy('e.account', 'm')
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['accountId']][(int) $row['m']] = (string) $row['total'];
        }

        return $out;
    }

    /**
     * Apuntes de un mes, con su cuenta y su partida ya cargadas y ordenados por fecha.
     * Es lo que pinta la pantalla del mes, agrupado después por grupo y partida.
     *
     * @return AccountEntry[]
     */
    public function findForMonth(int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        return $this->createQueryBuilder('e')
            ->innerJoin('e.account', 'a')->addSelect('a')
            ->innerJoin('e.category', 'c')->addSelect('c')
            ->innerJoin('c.group', 'g')->addSelect('g')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $from->modify('last day of this month'))
            ->orderBy('g.sortOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->addOrderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Consulta del libro con los filtros de la pantalla, lista para paginar. Devuelve
     * el constructor y no el resultado porque el paginador necesita la consulta sin
     * ejecutar para contar y trocear.
     *
     * @param array{account?: ?FinancialAccount, category?: ?int, group?: ?int, from?: ?\DateTimeInterface, to?: ?\DateTimeInterface, text?: ?string} $filters
     */
    public function listingQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->innerJoin('e.account', 'a')->addSelect('a')
            ->innerJoin('e.category', 'c')->addSelect('c')
            ->innerJoin('c.group', 'g')->addSelect('g')
            ->orderBy('e.date', 'DESC')
            ->addOrderBy('e.id', 'DESC');

        if (!empty($filters['account'])) {
            $qb->andWhere('e.account = :account')->setParameter('account', $filters['account']);
        }
        if (!empty($filters['category'])) {
            $qb->andWhere('e.category = :category')->setParameter('category', $filters['category']);
        }
        if (!empty($filters['group'])) {
            $qb->andWhere('c.group = :group')->setParameter('group', $filters['group']);
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('e.date >= :from')->setParameter('from', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('e.date <= :to')->setParameter('to', $filters['to']);
        }
        if (!empty($filters['text'])) {
            $qb->andWhere('e.concept LIKE :text OR e.providerName LIKE :text OR e.invoiceNumber LIKE :text')
                ->setParameter('text', '%'.$filters['text'].'%');
        }

        return $qb;
    }

    /**
     * Traspasos a los que les falta la otra mitad. Un traspaso sin pareja deja el
     * saldo de una cuenta mal sin que nada lo delate: en el libro de 2026 los
     * traspasos descuadran en 615 € por esto. La pantalla del libro los señala.
     *
     * @return AccountEntry[]
     */
    public function findUnpairedTransfers(): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.category', 'c')->addSelect('c')
            ->innerJoin('c.group', 'g')->addSelect('g')
            ->andWhere('g.kind = :transfer')->setParameter('transfer', BudgetCategoryGroup::KIND_TRANSFER)
            ->andWhere('e.transferPeer IS NULL')
            ->orderBy('e.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Redondea a dos decimales con punto, como los espera una columna DECIMAL. */
    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
