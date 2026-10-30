<?php

namespace App\Repository;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\IssuedInvoice;
use App\Entity\ReceivedInvoice;
use App\Entity\RemittanceReturn;
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
     * Saldo de la cuenta tras cada apunte de la lista, como la columna «saldo» del
     * extracto del banco: `[id del apunte => saldo]`. Los apuntes tienen que ser todos
     * de esa cuenta y venir en el orden del libro ({@see listingQueryBuilder}), y sin
     * huecos: con un filtro de partida o de texto el saldo de una fila no se deduce
     * de la anterior.
     *
     * Una sola consulta, para el primer apunte; el resto se arrastra fila a fila. Un
     * apunte anterior a la apertura no entra en el saldo y se queda sin cifra.
     *
     * @param AccountEntry[] $entries
     * @param bool $ascending El sentido en que vienen.
     *
     * @return array<int, string>
     */
    public function runningBalances(FinancialAccount $account, array $entries, bool $ascending): array
    {
        $counted = array_values(array_filter($entries, static fn (AccountEntry $e): bool => $account->isOpenOn($e->getDate())));
        if ($counted === []) {
            return [];
        }

        $balances = [];
        $cents = IssuedInvoice::toCents($this->balanceAfter($account, $counted[0]));
        foreach ($counted as $i => $entry) {
            if ($i > 0) {
                $cents += $ascending
                    ? IssuedInvoice::toCents($entry->getAmount())
                    : -IssuedInvoice::toCents($counted[$i - 1]->getAmount());
            }
            $balances[(int) $entry->getId()] = IssuedInvoice::fromCents($cents);
        }

        return $balances;
    }

    /** Saldo de la cuenta justo después de un apunte: lo de días anteriores y lo anotado antes ese mismo día. */
    private function balanceAfter(FinancialAccount $account, AccountEntry $entry): string
    {
        $moved = (string) $this->createQueryBuilder('e')
            ->select('COALESCE(SUM(e.amount), 0)')
            ->andWhere('e.account = :account')->setParameter('account', $account)
            ->andWhere('e.date >= :opening')->setParameter('opening', $account->getOpeningDate())
            ->andWhere('e.date < :date OR (e.date = :date AND e.id <= :id)')
            ->setParameter('date', $entry->getDate())
            ->setParameter('id', $entry->getId())
            ->getQuery()
            ->getSingleScalarResult();

        return IssuedInvoice::fromCents(IssuedInvoice::toCents($account->getOpeningBalance()) + IssuedInvoice::toCents($moved));
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
     * Fecha del último apunte de cada cuenta: `[accountId => fecha]`. Las cuentas sin
     * apuntes no salen. Es «hasta dónde está el libro al día» de cada cuenta, en una
     * sola consulta para todas.
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function lastDateByAccount(): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.account) AS accountId', 'MAX(e.date) AS lastDate')
            ->groupBy('e.account')
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['accountId']] = new \DateTimeImmutable($row['lastDate']);
        }

        return $out;
    }

    /**
     * Hasta dónde está al día el libro de los bancos: el último apunte de la cuenta
     * bancaria abierta que va más atrasada. Las cajas de efectivo no cuentan (se
     * apuntan a su ritmo) ni las cuentas sin ningún apunte (no se usan). Null si
     * ningún banco tiene apuntes.
     */
    public function bankBookUpTo(): ?\DateTimeImmutable
    {
        $rows = $this->createQueryBuilder('e')
            ->select('MAX(e.date) AS lastDate')
            ->join('e.account', 'a')
            ->andWhere('a.kind = :bank')
            ->andWhere('a.active = true')
            ->setParameter('bank', FinancialAccount::KIND_BANK)
            ->groupBy('a.id')
            ->getQuery()
            ->getSingleColumnResult();

        return $rows === [] ? null : new \DateTimeImmutable(min($rows));
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
     * Lo que había en todas las cuentas juntas al cerrar cada mes de un año, y al
     * empezarlo (índice 0): `[0..12] => saldo`. Es el dinero de verdad, con todo lo
     * que mueve el banco: también los apuntes aún sin partida, y sin que los traspasos
     * cuenten (una mitad sale de una cuenta y la otra entra en otra).
     *
     * Es la suma de {@see balancesByMonthEnd}: el total y el saldo de cada cuenta no
     * pueden contar distinto. Null si ninguna cuenta estaba abierta en el año: entonces
     * no hay saldo real del que partir.
     *
     * @return array<int, float>|null
     */
    public function cashByMonthEnd(int $year): ?array
    {
        $rows = $this->balancesByMonthEnd($year);
        if ($rows === []) {
            return null;
        }

        return array_map(
            static fn (int $m): float => round(array_sum(array_map(static fn (array $row): float => $row['balances'][$m] ?? 0.0, $rows)), 2),
            range(0, 12),
        );
    }

    /**
     * El saldo de cada cuenta al cerrar cada mes de un año, y al empezarlo (índice 0,
     * el saldo de partida que viene del año anterior), en el orden de las cuentas:
     * `[['account' => cuenta, 'balances' => [0..12 => saldo]]]`.
     *
     * Una cuenta suma desde su fecha de apertura, con su saldo de apertura, como en
     * {@see balanceFor}; antes no existe y sus meses van a null. Las que abren después
     * del año no salen.
     *
     * Dos consultas para todas las cuentas y meses, en vez de un saldo por cuenta y mes.
     *
     * @return list<array{account: FinancialAccount, balances: array<int, float|null>}>
     */
    public function balancesByMonthEnd(int $year): array
    {
        $accounts = $this->getEntityManager()->getRepository(FinancialAccount::class)->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        $yearStart = new \DateTimeImmutable($year.'-01-01');
        $yearEnd = new \DateTimeImmutable($year.'-12-31');
        $opened = array_values(array_filter($accounts, static fn (FinancialAccount $a) => $a->getOpeningDate() <= $yearEnd));
        if ($opened === []) {
            return [];
        }

        // Lo movido antes del año y dentro de él, mes a mes; siempre desde la apertura
        // de cada cuenta, como en balanceFor().
        $before = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.account) AS accountId', 'SUM(e.amount) AS total')
            ->join('e.account', 'a')
            ->andWhere('e.date >= a.openingDate')
            ->andWhere('e.date < :from')->setParameter('from', $yearStart)
            ->groupBy('e.account')
            ->getQuery()
            ->getScalarResult();
        $within = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.account) AS accountId', 'MONTH(e.date) AS m', 'SUM(e.amount) AS total')
            ->join('e.account', 'a')
            ->andWhere('e.date >= a.openingDate')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', $yearStart)
            ->setParameter('to', $yearEnd)
            ->groupBy('e.account', 'm')
            ->getQuery()
            ->getScalarResult();

        $beforeByAccount = array_column($before, 'total', 'accountId');
        $monthly = [];
        foreach ($within as $row) {
            $monthly[(int) $row['accountId']][(int) $row['m']] = (float) $row['total'];
        }

        return array_map(static function (FinancialAccount $account) use ($beforeByAccount, $monthly, $yearStart): array {
            $id = (int) $account->getId();
            $running = (float) $account->getOpeningBalance() + (float) ($beforeByAccount[$id] ?? 0);
            // Abierta el 1 de enero o antes, su saldo de apertura ya estaba al empezar el año.
            $openingMonth = $account->getOpeningDate() <= $yearStart ? 0 : (int) $account->getOpeningDate()->format('n');
            $balances = [];
            for ($m = 0; $m <= 12; ++$m) {
                $running += $monthly[$id][$m] ?? 0.0;
                $balances[$m] = $m >= $openingMonth ? round($running, 2) : null;
            }

            return ['account' => $account, 'balances' => $balances];
        }, $opened);
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
     * Dentro de un mismo día manda el orden en que se anotaron (el id), en el mismo
     * sentido que la fecha: si no, al ordenar de antiguo a nuevo los movimientos de
     * cada día saldrían al revés y el saldo de cada fila no se podría seguir.
     *
     * @param array{account?: ?FinancialAccount, category?: ?int, group?: ?int, from?: ?\DateTimeInterface, to?: ?\DateTimeInterface, text?: ?string} $filters
     * @param bool $ascending De antiguo a nuevo; por defecto, lo último primero.
     */
    public function listingQueryBuilder(array $filters = [], bool $ascending = false): QueryBuilder
    {
        $direction = $ascending ? 'ASC' : 'DESC';
        $qb = $this->createQueryBuilder('e')
            ->innerJoin('e.account', 'a')->addSelect('a')
            ->innerJoin('e.category', 'c')->addSelect('c')
            ->innerJoin('c.group', 'g')->addSelect('g')
            ->orderBy('e.date', $direction)
            ->addOrderBy('e.id', $direction);

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

    /** Días antes de la fecha de una factura en que puede estar ya su pago en el libro. */
    public const PAYMENT_DAYS_BEFORE = 7;

    /**
     * Días después: una transferencia sale a los pocos días, la tarjeta se carga el 5
     * del mes siguiente y una domiciliación puede tardar un mes.
     */
    public const PAYMENT_DAYS_AFTER = 45;

    /**
     * Días después para el COBRO de una factura emitida: quien paga es otro y a su
     * ritmo; los clientes son sobre todo organismos públicos, que pagan tras aprobar
     * el gasto (IMIDRA tardó 28 días en la 10/2026).
     */
    public const COLLECTION_DAYS_AFTER = 90;

    /**
     * Apuntes que pueden ser el pago de una factura (recibida: un gasto; emitida: un
     * cobro, en positivo): el mismo importe exacto, en fechas cercanas, que aún no
     * explica ninguna otra factura, ni recibida ni emitida. El que más se
     * acerca en fecha, primero.
     *
     * Es lo que evita contar un gasto dos veces: el movimiento lo trae el banco (el
     * extracto ya está en el libro) y la factura sólo lo explica.
     *
     * Fuera los traspasos (no son pagos) y lo que cuelga de una cuenta o partida
     * retirada, que la revisión no podría ofrecer.
     *
     * @param string             $amount Importe con signo, como en el apunte (un gasto es negativo).
     * @param \DateTimeInterface $date   Fecha de la factura.
     * @param int                $limit     Cuántos como mucho.
     * @param int                $daysAfter Hasta cuántos días después de la factura buscar.
     *
     * @return list<AccountEntry>
     */
    public function findPaymentCandidates(string $amount, \DateTimeInterface $date, int $limit = 3, int $daysAfter = self::PAYMENT_DAYS_AFTER): array
    {
        $day = \DateTimeImmutable::createFromInterface($date);

        return $this->unexplainedPayments($day->modify(sprintf('-%d days', self::PAYMENT_DAYS_BEFORE)), $day->modify(sprintf('+%d days', $daysAfter)))
            ->addSelect('ABS(DATE_DIFF(e.date, :day)) AS HIDDEN distance')
            ->andWhere('e.amount = :amount')
            ->setParameter('amount', $amount)
            ->setParameter('day', $day->format('Y-m-d'))
            ->orderBy('distance', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Apuntes que pueden ser el pago de varias facturas a la vez: uno de esos importes,
     * entre dos fechas, y que aún no explica ninguna factura. Los más antiguos primero.
     *
     * @param list<string> $amounts Importes con signo, como en el apunte.
     *
     * @return list<AccountEntry>
     */
    public function findPaymentsAmong(array $amounts, \DateTimeInterface $from, \DateTimeInterface $to, int $limit = 3): array
    {
        if ($amounts === []) {
            return [];
        }

        return $this->unexplainedPayments($from, $to)
            ->andWhere('e.amount IN (:amounts)')
            ->setParameter('amounts', $amounts)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Lo común a buscar el pago de una factura: entre dos fechas, sin ninguna factura
     * (recibida ni emitida) que ya lo explique, que no sea un traspaso, y en una
     * cuenta y una partida que se pueden elegir.
     */
    private function unexplainedPayments(\DateTimeInterface $from, \DateTimeInterface $to): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.account', 'a')->addSelect('a')
            ->innerJoin('e.category', 'c')->addSelect('c')
            ->innerJoin('c.group', 'g')
            ->leftJoin(ReceivedInvoice::class, 'ri', 'WITH', 'ri.accountEntry = e')
            ->leftJoin(IssuedInvoice::class, 'ii', 'WITH', 'ii.accountEntry = e')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->andWhere('ri.id IS NULL')
            ->andWhere('ii.id IS NULL')
            ->andWhere('g.kind != :transfer')
            ->andWhere('a.active = true')
            ->andWhere('c.active = true')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('transfer', BudgetCategoryGroup::KIND_TRANSFER);
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

    /**
     * Traspasos sin pareja de otras cuentas a como mucho `$days` días de un apunte:
     * de donde sale la mitad que le falta. Quién casa de verdad lo dice
     * {@see \App\Service\Accounting\TransferRecorder::canPair}.
     *
     * @return list<AccountEntry>
     */
    public function findLooseTransfersNear(AccountEntry $entry, int $days): array
    {
        $date = \DateTimeImmutable::createFromInterface($entry->getDate());

        return $this->createQueryBuilder('e')
            ->innerJoin('e.account', 'a')->addSelect('a')
            ->innerJoin('e.category', 'c')->addSelect('c')
            ->innerJoin('c.group', 'g')->addSelect('g')
            ->andWhere('g.kind = :transfer')->setParameter('transfer', BudgetCategoryGroup::KIND_TRANSFER)
            ->andWhere('e.transferPeer IS NULL')
            ->andWhere('e.account != :account')->setParameter('account', $entry->getAccount())
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', $date->modify(sprintf('-%d days', $days)))
            ->setParameter('to', $date->modify(sprintf('+%d days', $days)))
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Gastos de un periodo que tendrían que llevar factura y no tienen ninguna recibida
     * ligada: lo que la gestoría echará en falta. Sólo partidas de gasto o inversión
     * que la piden ({@see \App\Entity\BudgetCategory::expectsInvoice()}, que dice lo
     * mismo para un apunte suelto). Por fecha.
     *
     * @return list<AccountEntry>
     */
    public function findExpensesWithoutInvoice(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.account', 'a')->addSelect('a')
            ->innerJoin('e.category', 'c')->addSelect('c')
            ->innerJoin('c.group', 'g')
            ->leftJoin(ReceivedInvoice::class, 'ri', 'WITH', 'ri.accountEntry = e')
            ->andWhere('ri.id IS NULL')
            ->andWhere('g.kind IN (:kinds)')->setParameter('kinds', BudgetCategoryGroup::INVOICED_KINDS)
            ->andWhere('c.requiresInvoice = true')
            ->andWhere('e.amount < 0')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Los apuntes cuyo concepto empieza por un prefijo, entre dos fechas: los abonos y
     * las devoluciones de remesa de Fiare, que llevan la referencia del banco detrás.
     *
     * @return list<AccountEntry>
     */
    public function findByConceptPrefix(string $prefix, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.concept LIKE :prefix')->setParameter('prefix', addcslashes($prefix, '%_') . '%')
            ->andWhere('e.date BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Los apuntes con ese prefijo desde una fecha que aún no son la devolución de
     * ningún recibo: lo que el banco ha devuelto sin saber todavía de quién es.
     *
     * @return list<AccountEntry>
     */
    public function findUnlinkedReturnCharges(string $prefix, \DateTimeInterface $from): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin(RemittanceReturn::class, 'x', 'WITH', 'x.entry = e')
            ->andWhere('x.id IS NULL')
            ->andWhere('e.concept LIKE :prefix')->setParameter('prefix', addcslashes($prefix, '%_') . '%')
            ->andWhere('e.date >= :from')->setParameter('from', $from)
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ingresos de un importe desde una fecha, que pueden ser el pago aparte de un
     * recibo devuelto: una transferencia o lo que se dejó en caja, que no pague ya otra
     * devolución. Los más antiguos primero, que el pago suele llegar poco después de la devolución.
     *
     * @param string $amount Importe positivo, «55.00».
     *
     * @return list<AccountEntry>
     */
    public function findIncomeOf(string $amount, \DateTimeInterface $from, int $limit = 10): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.account', 'a')->addSelect('a')
            ->andWhere('e.amount = :amount')->setParameter('amount', $amount)
            ->andWhere('e.date >= :from')->setParameter('from', $from)
            ->andWhere('e.transferPeer IS NULL')
            ->leftJoin(RemittanceReturn::class, 'x', 'WITH', 'x.paidWith = e')
            ->andWhere('x.id IS NULL')
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Redondea a dos decimales con punto, como los espera una columna DECIMAL. */
    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
