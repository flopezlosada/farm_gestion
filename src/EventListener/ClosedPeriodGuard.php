<?php

namespace App\EventListener;

use App\Entity\AccountEntry;
use App\Entity\IssuedInvoice;
use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Closing\ClosedPeriodException;
use App\Service\Accounting\Closing\ClosedPeriods;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Nada fechado en un trimestre entregado a la gestoría se crea, se cambia ni se
 * borra, venga de donde venga: pantalla, bot de Telegram o comando. Es la red; el
 * mensaje a quien lo intenta lo pone {@see ClosedPeriodExceptionListener}.
 *
 * Va en `onFlush`, que Doctrine lanza antes de abrir la transacción y fuera del
 * bloque que cierra el EntityManager si algo falla (UnitOfWork::commit, ORM 2.20):
 * lanzar aquí no escribe nada y no deja el EntityManager inservible.
 *
 * Qué fecha cuenta en cada caso, la misma con la que entra en el paquete:
 *  - apunte: su fecha, la nueva y la de antes (sacar un apunte de un trimestre
 *    entregado también lo cambia);
 *  - factura recibida: sólo confirmada (antes o después del cambio), por su fecha de
 *    factura o, sin ella, la de su apunte;
 *  - factura emitida: sólo emitida, por su fecha de emisión. Ligar el cobro no cuenta:
 *    cobrar en octubre una factura de septiembre no cambia lo entregado.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class ClosedPeriodGuard
{
    /** Campos cuyo cambio no toca lo entregado. */
    private const IGNORED_FIELDS = ['updatedAt'];

    /** En una emitida, además: el apunte en que se cobró. */
    private const ISSUED_IGNORED_FIELDS = ['updatedAt', 'accountEntry'];

    public function __construct(private readonly ClosedPeriods $closed)
    {
    }

    /**
     * @throws ClosedPeriodException Si algo de este guardado cae en un trimestre entregado.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            $this->closed->assertOpen(...$this->datesOf($entity, []));
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changes = $uow->getEntityChangeSet($entity);
            $ignored = $entity instanceof IssuedInvoice ? self::ISSUED_IGNORED_FIELDS : self::IGNORED_FIELDS;
            if (array_diff(array_keys($changes), $ignored) === []) {
                continue;
            }
            $this->closed->assertOpen(...$this->datesOf($entity, $changes));
        }
    }

    /**
     * Las fechas que sitúan una entidad en el paquete de la gestoría: la actual y, si
     * cambia, la de antes. Ninguna si la entidad no va en el paquete.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changes Cambios, de {@see UnitOfWork::getEntityChangeSet()}.
     *
     * @return list<?\DateTimeInterface>
     */
    private function datesOf(object $entity, array $changes): array
    {
        $before = static fn (string $field, mixed $now): mixed => \array_key_exists($field, $changes) ? $changes[$field][0] : $now;

        return match (true) {
            $entity instanceof AccountEntry => [$entity->getDate(), $before('date', null)],
            $entity instanceof ReceivedInvoice => $this->receivedDates($entity, $before),
            $entity instanceof IssuedInvoice => \in_array(IssuedInvoice::STATUS_ISSUED, [$entity->getStatus(), $before('status', null)], true)
                ? [$entity->getIssueDate(), $before('issueDate', null)]
                : [],
            default => [],
        };
    }

    /**
     * @param callable(string, mixed): mixed $before
     *
     * @return list<?\DateTimeInterface>
     */
    private function receivedDates(ReceivedInvoice $invoice, callable $before): array
    {
        if (!\in_array(ReceivedInvoice::STATUS_CONFIRMED, [$invoice->getStatus(), $before('status', null)], true)) {
            return [];
        }
        $previousEntry = $before('accountEntry', $invoice->getAccountEntry());

        return [
            $invoice->getInvoiceDate() ?? $invoice->getAccountEntry()?->getDate(),
            $before('invoiceDate', $invoice->getInvoiceDate()) ?? $previousEntry?->getDate(),
        ];
    }
}
