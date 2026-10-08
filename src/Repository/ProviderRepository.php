<?php

namespace App\Repository;

use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Provider>
 */
class ProviderRepository extends ServiceEntityRepository
{
    /** A partir de este total anual con un proveedor hay que declararlo en el modelo 347. */
    public const MODEL_347_THRESHOLD = 3005.06;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Provider::class);
    }

    /**
     * El proveedor con este CIF, comparado ya normalizado.
     *
     * @param string|null $taxId CIF o NIF.
     */
    public function findOneByTaxId(?string $taxId): ?Provider
    {
        $taxId = Provider::normalizeTaxId($taxId);

        return $taxId === null ? null : $this->findOneBy(['taxId' => $taxId]);
    }

    /**
     * Todos los proveedores con lo que se les ha facturado en el año: número de
     * facturas anotadas y su total con IVA. Es la base del modelo 347, que pide
     * declarar a quien supere 3.005,06 € en el año. Los que no tienen facturas
     * salen con cero, al final.
     *
     * @param int $year Año de la fecha de factura.
     *
     * @return list<array{provider: Provider, invoices: int, total: float}>
     */
    public function findWithYearTotals(int $year): array
    {
        $rows = $this->createQueryBuilder('p')
            // La fecha es la de la factura o, si no se leyó, la del apunte: el mismo
            // criterio que la entrega a la gestoría. Va dentro de la suma y no en el
            // WHERE para que los proveedores sin facturas en el año sigan saliendo.
            ->select(
                'p AS provider',
                'SUM(CASE WHEN COALESCE(i.invoiceDate, e.date) BETWEEN :from AND :to THEN 1 ELSE 0 END) AS invoices',
                'SUM(CASE WHEN COALESCE(i.invoiceDate, e.date) BETWEEN :from AND :to THEN i.total ELSE 0 END) AS total',
            )
            ->leftJoin(ReceivedInvoice::class, 'i', 'WITH', 'i.provider = p AND i.status = :confirmed')
            ->leftJoin('i.accountEntry', 'e')
            ->setParameter('confirmed', ReceivedInvoice::STATUS_CONFIRMED)
            ->setParameter('from', sprintf('%d-01-01', $year))
            ->setParameter('to', sprintf('%d-12-31', $year))
            ->groupBy('p.id')
            ->orderBy('total', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => [
            'provider' => $row['provider'],
            'invoices' => (int) $row['invoices'],
            'total' => (float) $row['total'],
        ], $rows);
    }
}
