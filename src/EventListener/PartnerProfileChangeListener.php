<?php

namespace App\EventListener;

use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerEvent;
use App\Service\Partner\PartnerEventActor;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Events;

/**
 * Deja en el histórico de la ficha un {@see PartnerEvent::TYPE_PROFILE_UPDATE}
 * cada vez que alguien guarda cambios en los datos de una persona socia o en
 * quién paga su cesta.
 *
 * Va en `onFlush` y no en cada controlador porque la ficha se edita desde varios
 * sitios (gestión, «Mi perfil» del socix, «La paga otrx socix»): así ninguno se
 * queda sin rastro por olvido. Sólo registra con alguien en sesión: los
 * comandos e importaciones (anonimizar staging, fixtures…) reescriben fichas en
 * bloque y no son ediciones de nadie.
 *
 * Lista cerrada de campos, no un diff de columnas: las de control interno
 * (status, needs_review, updated…) tienen sus propios eventos o no le dicen
 * nada a quien lee la ficha. Los cambios se comparan ya normalizados, porque el
 * formulario devuelve fechas y textos como objetos nuevos aunque no cambien.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class PartnerProfileChangeListener
{
    /** Campos de Partner que dejan rastro con su valor anterior y nuevo. */
    private const TRACKED = [
        'name' => 'Nombre',
        'surname' => 'Apellidos',
        'display_name' => 'Nombre de reparto',
        'email' => 'Correo',
        'celular' => 'Teléfono',
        'address' => 'Dirección',
        'state' => 'Provincia',
        'city' => 'Municipio',
        'weekly_basket_group' => 'Grupo de recogida',
        'inscription_date' => 'Fecha de inscripción',
        'share_payment' => 'Forma de pago',
        'eat_meat' => 'Consume cárnicos',
        'mandateReference' => 'Referencia del mandato',
        'mandateSignedOn' => 'Firma del mandato',
    ];

    /**
     * Campos de Partner de los que sólo se apunta que cambiaron: datos
     * sensibles que no deben duplicarse en el histórico, o texto largo.
     */
    private const TRACKED_WITHOUT_VALUES = [
        'DNI' => 'DNI',
        'iban' => 'IBAN',
        'notes' => 'Notas',
    ];

    /** Campo de PartnerBasketShare que cuenta como cambio en la ficha de su socix. */
    private const PAYER_FIELD = 'payer_partner';
    private const PAYER_LABEL = 'Quién paga la cesta';

    public function __construct(
        private readonly PartnerEventActor $actor,
    ) {
    }

    /**
     * Junta los cambios de cada socix en este flush (los de su ficha y los de
     * quién paga su cesta) y persiste un único evento por socix.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var array<int, array{partner: Partner, changes: list<array<string, ?string>>}> $byPartner */
        $byPartner = [];
        $partnerMeta = $em->getClassMetadata(Partner::class);
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            [$partner, $changes] = match (true) {
                $entity instanceof Partner => [$entity, $this->partnerChanges($uow->getEntityChangeSet($entity), $partnerMeta)],
                $entity instanceof PartnerBasketShare => [$entity->getPartner(), $this->payerChanges($uow->getEntityChangeSet($entity))],
                default => [null, []],
            };
            if ($partner === null || $changes === []) {
                continue;
            }
            $key = spl_object_id($partner);
            $byPartner[$key]['partner'] = $partner;
            $byPartner[$key]['changes'] = [...($byPartner[$key]['changes'] ?? []), ...$changes];
        }

        $meta = $em->getClassMetadata(PartnerEvent::class);
        foreach ($byPartner as ['partner' => $partner, 'changes' => $changes]) {
            $actor = $this->actor->resolve($partner);
            if ($actor === PartnerEvent::ACTOR_SYSTEM) {
                continue;
            }
            $event = (new PartnerEvent($partner, PartnerEvent::TYPE_PROFILE_UPDATE))
                ->setActor($actor)
                ->setPayload(['changes' => $changes]);
            $em->persist($event);
            $uow->computeChangeSet($meta, $event);
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet Changeset de Doctrine de un Partner.
     * @param ClassMetadata<Partner> $meta Para saber qué campos son casillas.
     * @return list<array<string, ?string>> Cambios reales de los campos seguidos.
     */
    private function partnerChanges(array $changeSet, ClassMetadata $meta): array
    {
        $isCheckbox = static fn (string $field): bool => $meta->hasField($field) && $meta->getTypeOfField($field) === 'boolean';
        $withValues = array_map(
            fn (string $field): ?array => $this->change($field, self::TRACKED[$field], $changeSet[$field], true, $isCheckbox($field)),
            array_keys(array_intersect_key($changeSet, self::TRACKED)),
        );
        $withoutValues = array_map(
            fn (string $field): ?array => $this->change($field, self::TRACKED_WITHOUT_VALUES[$field], $changeSet[$field], false, $isCheckbox($field)),
            array_keys(array_intersect_key($changeSet, self::TRACKED_WITHOUT_VALUES)),
        );

        return array_values(array_filter([...$withValues, ...$withoutValues]));
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet Changeset de Doctrine de un PartnerBasketShare.
     * @return list<array<string, ?string>> El cambio de pagador, si lo hay.
     */
    private function payerChanges(array $changeSet): array
    {
        if (!isset($changeSet[self::PAYER_FIELD])) {
            return [];
        }

        return array_values(array_filter([
            $this->change(self::PAYER_FIELD, self::PAYER_LABEL, $changeSet[self::PAYER_FIELD], true, false),
        ]));
    }

    /**
     * Un cambio legible, o null si antes y después valen lo mismo una vez
     * normalizados (p. ej. la misma fecha en otro objeto, o null frente a '').
     * En las casillas, null cuenta como «no» (la casilla no sabe decir «sin
     * dato», y guardar una ficha antigua sin tocarla no es un cambio) y 0/1
     * como no/sí (hay setters que guardan el entero que les llega).
     *
     * @param array{0: mixed, 1: mixed} $pair Valor anterior y nuevo según Doctrine.
     * @param bool $withValues Si se guardan los valores o sólo que cambió.
     * @param bool $isCheckbox Si el campo es un booleano.
     * @return array<string, ?string>|null
     */
    private function change(string $field, string $label, array $pair, bool $withValues, bool $isCheckbox): ?array
    {
        [$before, $after] = $pair;
        if ($isCheckbox) {
            [$before, $after] = [(bool) $before, (bool) $after];
        }
        $from = self::readable($before);
        $to = self::readable($after);
        if ($from === $to) {
            return null;
        }

        return $withValues
            ? ['field' => $field, 'label' => $label, 'from' => $from, 'to' => $to]
            : ['field' => $field, 'label' => $label];
    }

    /**
     * Valor tal como lo lee una persona: fechas en d/m/Y, sí/no, el nombre de
     * reparto de un socix, el nombre de un catálogo. Vacío = null.
     */
    private static function readable(mixed $value): ?string
    {
        $text = match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => $value->format('d/m/Y'),
            is_bool($value) => $value ? 'Sí' : 'No',
            $value instanceof Partner => $value->getNameForDelivery(),
            $value instanceof \Stringable, is_scalar($value) => trim((string) $value),
            default => null,
        };

        return $text === '' ? null : $text;
    }
}
