<?php

namespace App\Repository;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReceivedInvoice>
 */
class ReceivedInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReceivedInvoice::class);
    }

    /**
     * Las que esperan lectura y ya pueden intentarse, las más antiguas primero.
     *
     * @param \DateTimeImmutable $now   Momento de referencia.
     * @param int                $limit Cuántas como mucho.
     * @param list<int>|null     $ids   Restringir a éstas (las recién subidas).
     *
     * @return list<ReceivedInvoice>
     */
    public function findDue(\DateTimeImmutable $now, int $limit, ?array $ids = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.status = :pending')
            ->andWhere('i.nextAttemptAt IS NULL OR i.nextAttemptAt <= :now')
            ->setParameter('pending', ReceivedInvoice::STATUS_PENDING)
            ->setParameter('now', $now)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults($limit);

        if ($ids !== null) {
            $qb->andWhere('i.id IN (:ids)')->setParameter('ids', $ids === [] ? [0] : $ids);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Reserva una factura para leerla, en una sola sentencia: si otro lector la ha
     * cogido entre medias, la actualización no toca ninguna fila y esta devuelve
     * false. Es lo que impide leer la misma factura dos veces (y gastar cupo dos
     * veces) cuando la lectura inmediata y la del planificador coinciden.
     *
     * @param ReceivedInvoice    $invoice Factura a reservar.
     * @param \DateTimeImmutable $now     Momento de referencia.
     * @param \DateTimeImmutable $until   Hasta cuándo queda reservada.
     */
    public function claim(ReceivedInvoice $invoice, \DateTimeImmutable $now, \DateTimeImmutable $until): bool
    {
        $updated = $this->getEntityManager()->createQuery(
            'UPDATE ' . ReceivedInvoice::class . ' i
                SET i.nextAttemptAt = :until, i.attempts = i.attempts + 1
              WHERE i.id = :id AND i.status = :pending
                AND (i.nextAttemptAt IS NULL OR i.nextAttemptAt <= :now)'
        )
            ->setParameter('until', $until)
            ->setParameter('id', $invoice->getId())
            ->setParameter('pending', ReceivedInvoice::STATUS_PENDING)
            ->setParameter('now', $now)
            ->execute();

        return $updated === 1;
    }

    /**
     * Pasa la factura a confirmada sólo si sigue abierta, en una sola sentencia.
     * Dentro de una transacción, InnoDB bloquea la fila: de dos confirmaciones a la
     * vez (un doble clic en «Guardar») la segunda espera, ve la factura ya
     * confirmada y no crea un segundo apunte.
     *
     * @param ReceivedInvoice $invoice La que se va a confirmar.
     *
     * @return bool Si era suya: false si otra petición se adelantó.
     */
    public function reserveForConfirmation(ReceivedInvoice $invoice): bool
    {
        $updated = $this->getEntityManager()->createQuery(
            'UPDATE ' . ReceivedInvoice::class . ' i
                SET i.status = :confirmed
              WHERE i.id = :id AND i.status IN (:open)'
        )
            ->setParameter('confirmed', ReceivedInvoice::STATUS_CONFIRMED)
            ->setParameter('id', $invoice->getId())
            ->setParameter('open', ReceivedInvoice::OPEN_STATUSES)
            ->execute();

        return $updated === 1;
    }

    /**
     * Lo que está por resolver en la bandeja: en cola, leídas y no leídas.
     *
     * @return list<ReceivedInvoice>
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.suggestedCategory', 'c')->addSelect('c')
            ->andWhere('i.status IN (:open)')
            ->setParameter('open', [ReceivedInvoice::STATUS_PENDING, ReceivedInvoice::STATUS_READ, ReceivedInvoice::STATUS_UNREADABLE])
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las últimas ya anotadas, para comprobar de un vistazo qué se hizo.
     *
     * @return list<ReceivedInvoice>
     */
    public function findRecentlyConfirmed(int $limit): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.accountEntry', 'e')->addSelect('e')
            ->andWhere('i.status = :confirmed')
            ->setParameter('confirmed', ReceivedInvoice::STATUS_CONFIRMED)
            ->orderBy('i.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Qué apuntes de una lista vienen de una factura, para marcarlos en el libro sin
     * preguntar apunte por apunte.
     *
     * @param list<int> $entryIds Apuntes de la página.
     *
     * @return array<int, int> id del apunte => id de la factura.
     */
    public function invoiceIdsByEntry(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.accountEntry) AS entry', 'i.id AS invoice')
            ->andWhere('i.accountEntry IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'invoice', 'entry');
    }

    /**
     * Las facturas anotadas de un proveedor, las más recientes primero.
     *
     * @param Provider $provider El proveedor.
     *
     * @return list<ReceivedInvoice>
     */
    public function findConfirmedByProvider(Provider $provider): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.accountEntry', 'e')->addSelect('e')
            ->leftJoin('e.category', 'c')->addSelect('c')
            ->andWhere('i.provider = :provider')
            ->andWhere('i.status = :confirmed')
            ->setParameter('provider', $provider)
            ->setParameter('confirmed', ReceivedInvoice::STATUS_CONFIRMED)
            ->orderBy('i.invoiceDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Otra factura ya anotada con el mismo proveedor y número: casi seguro la misma,
     * entregada dos veces (el trabajador la manda y la tesorera la sube del correo).
     * Anotarla otra vez contaría el gasto doble.
     *
     * @param ReceivedInvoice $invoice La que se está revisando.
     */
    public function findAlreadyConfirmedTwin(ReceivedInvoice $invoice): ?ReceivedInvoice
    {
        if ($invoice->getInvoiceNumber() === null || ($invoice->getProviderTaxId() === null && $invoice->getProviderName() === null)) {
            return null;
        }

        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.status = :confirmed')
            ->andWhere('i.invoiceNumber = :number')
            ->andWhere('i.id != :id')
            ->setParameter('confirmed', ReceivedInvoice::STATUS_CONFIRMED)
            ->setParameter('number', $invoice->getInvoiceNumber())
            ->setParameter('id', (int) $invoice->getId())
            ->setMaxResults(1);

        if ($invoice->getProviderTaxId() !== null) {
            $qb->andWhere('i.providerTaxId = :taxId')->setParameter('taxId', $invoice->getProviderTaxId());
        } else {
            $qb->andWhere('i.providerName = :name')->setParameter('name', $invoice->getProviderName());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * La partida que se usó otras veces para este proveedor: la más repetida.
     *
     * Primero por CIF entre las facturas ya anotadas, que es la señal fiable. Si el
     * proveedor no tiene facturas anotadas todavía, por su nombre en el libro, que
     * trae el historial importado del Excel. La comparación por nombre la hace la
     * colación de MySQL, que no distingue mayúsculas ni tildes: «FERRETERÍA
     * TORRELAGUNA» y «Ferreteria Torrelaguna» son el mismo.
     *
     * @param string|null $taxId        CIF del proveedor.
     * @param string|null $providerName Nombre del proveedor.
     */
    public function usualCategoryFor(?string $taxId, ?string $providerName): ?BudgetCategory
    {
        $em = $this->getEntityManager();

        if ($taxId !== null && $taxId !== '') {
            $row = $em->createQuery(
                'SELECT IDENTITY(e.category) AS id, COUNT(e.id) AS n
                   FROM ' . ReceivedInvoice::class . ' i JOIN i.accountEntry e
                  WHERE i.providerTaxId = :taxId
                  GROUP BY e.category ORDER BY n DESC'
            )->setParameter('taxId', $taxId)->setMaxResults(1)->getOneOrNullResult();

            if ($row !== null) {
                return $em->find(BudgetCategory::class, (int) $row['id']);
            }
        }

        if ($providerName !== null && $providerName !== '') {
            $row = $em->createQuery(
                'SELECT IDENTITY(e.category) AS id, COUNT(e.id) AS n
                   FROM ' . AccountEntry::class . ' e
                  WHERE e.providerName = :name
                  GROUP BY e.category ORDER BY n DESC'
            )->setParameter('name', $providerName)->setMaxResults(1)->getOneOrNullResult();

            if ($row !== null) {
                return $em->find(BudgetCategory::class, (int) $row['id']);
            }
        }

        return null;
    }

    /**
     * La cuenta con la que se pagó otras veces a este proveedor, por CIF. Es mejor
     * pista que la forma de pago impresa en la factura.
     *
     * @param string|null $taxId CIF del proveedor.
     */
    public function usualAccountIdFor(?string $taxId): ?int
    {
        if ($taxId === null || $taxId === '') {
            return null;
        }

        $row = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(e.account) AS id, COUNT(e.id) AS n
               FROM ' . ReceivedInvoice::class . ' i JOIN i.accountEntry e
              WHERE i.providerTaxId = :taxId
              GROUP BY e.account ORDER BY n DESC'
        )->setParameter('taxId', $taxId)->setMaxResults(1)->getOneOrNullResult();

        return $row !== null ? (int) $row['id'] : null;
    }
}
