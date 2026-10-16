<?php

namespace App\Service\Accounting\Closing;

use App\Entity\AccountantDelivery;
use App\Entity\User;
use App\Repository\AccountantDeliveryRepository;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Qué trimestres están entregados a la gestoría, y por tanto cerrados, y el sitio
 * por el que se entregan y se reabren.
 *
 * Las entregas se leen una vez por petición: la red
 * ({@see \App\EventListener\ClosedPeriodGuard}) pregunta en cada guardado y no
 * debe consultar la base cada vez. Entregar o reabrir desde aquí olvida lo leído.
 */
class ClosedPeriods implements ResetInterface
{
    /** @var array<string, AccountantDelivery>|null */
    private ?array $latest = null;

    public function __construct(
        private readonly AccountantDeliveryRepository $deliveries,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * La entrega que cierra el trimestre de una fecha, o null si ese trimestre está
     * abierto (nunca se entregó, o se reabrió y no se ha vuelto a mandar).
     */
    public function closingDeliveryFor(\DateTimeInterface $date): ?AccountantDelivery
    {
        $delivery = $this->latestOf(InvoiceQuarter::containing($date));

        return $delivery !== null && $delivery->isStanding() ? $delivery : null;
    }

    /**
     * Comprueba que ninguna de las fechas cae en un trimestre entregado.
     *
     * @throws ClosedPeriodException Si alguna cae.
     */
    public function assertOpen(?\DateTimeInterface ...$dates): void
    {
        foreach (array_filter($dates) as $date) {
            if (($delivery = $this->closingDeliveryFor($date)) !== null) {
                throw new ClosedPeriodException($delivery);
            }
        }
    }

    /** La entrega más reciente de un trimestre, siga en pie o no; null si nunca se entregó. */
    public function latestOf(InvoiceQuarter $quarter): ?AccountantDelivery
    {
        $this->latest ??= $this->deliveries->latestByQuarter();

        return $this->latest[$quarter->year.'-'.$quarter->quarter] ?? null;
    }

    /**
     * Da el trimestre por entregado.
     *
     * @throws \LogicException Si ya lo estaba.
     */
    public function deliver(InvoiceQuarter $quarter, ?User $by): AccountantDelivery
    {
        if ($this->latestOf($quarter)?->isStanding()) {
            throw new \LogicException(sprintf('El %s ya está entregado.', $quarter->label()));
        }
        $delivery = new AccountantDelivery($quarter, $by, new \DateTimeImmutable());
        $this->em->persist($delivery);
        $this->em->flush();
        $this->reset();

        return $delivery;
    }

    /**
     * Reabre el trimestre para corregirlo.
     *
     * @throws \LogicException Si no estaba entregado.
     */
    public function reopen(InvoiceQuarter $quarter, ?User $by): void
    {
        $delivery = $this->latestOf($quarter);
        if ($delivery === null || !$delivery->isStanding()) {
            throw new \LogicException(sprintf('El %s no está entregado.', $quarter->label()));
        }
        $delivery->reopen($by, new \DateTimeImmutable());
        $this->em->flush();
        $this->reset();
    }

    public function reset(): void
    {
        $this->latest = null;
    }
}
