<?php

namespace App\Service\Delivery\Invariant;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Evalúa el conjunto de leyes del reparto y devuelve los resultados en
 * estructura neutra, para que cada consumidor los formatee a su manera:
 * el comando de invariantes (informe propio) y la batería de verificación
 * (los 17+ jueces sobre el clon recién ejercitado).
 */
final class InvariantSuite
{
    /**
     * @param iterable<DeliveryInvariant> $invariants Leyes registradas vía tag.
     */
    public function __construct(
        #[AutowireIterator('app.delivery_invariant')]
        private readonly iterable $invariants,
    ) {
    }

    /**
     * Evalúa las leyes (todas, o solo las de los códigos dados) ordenadas por
     * código natural.
     *
     * @param \DateTimeImmutable $from      Inicio de la ventana de validación.
     * @param string[]           $onlyCodes Códigos a evaluar (vacío = todas).
     * @return list<array{code:string, name:string, severity:string, violations:list<string>}>
     */
    public function run(\DateTimeImmutable $from, array $onlyCodes = []): array
    {
        $wanted = array_map('strtoupper', $onlyCodes);

        $laws = [];
        foreach ($this->invariants as $invariant) {
            if ($wanted === [] || in_array(strtoupper($invariant->code()), $wanted, true)) {
                $laws[] = $invariant;
            }
        }
        usort($laws, static fn (DeliveryInvariant $a, DeliveryInvariant $b): int => strnatcmp($a->code(), $b->code()));

        return array_map(
            static fn (DeliveryInvariant $law): array => [
                'code' => $law->code(),
                'name' => $law->name(),
                'severity' => $law->severity(),
                'violations' => $law->check($from),
            ],
            $laws
        );
    }
}
