<?php

namespace App\Tests\Service\Accounting\Duplicate;

use App\Entity\AccountEntry;
use App\Entity\Provider;
use App\Service\Accounting\Duplicate\DuplicateEntryRule;
use PHPUnit\Framework\TestCase;

/**
 * Dos apuntes iguales en importe y fecha sólo son un posible duplicado si dicen lo
 * mismo: el concepto (sin cifras, signos ni mayúsculas) o la ficha de proveedor. Los
 * casos son del libro real de Fiare en 2026.
 */
class DuplicateEntryRuleTest extends TestCase
{
    private int $nextId = 1;

    /** El mismo cargo escrito desde el Excel y desde el extracto. */
    public function testElMismoConceptoEsDuplicado(): void
    {
        $this->assertTrue(DuplicateEntryRule::isLikely(
            $this->entry('trf. ayuntamiento de torremocha de jarama'),
            $this->entry('TRF. AYUNTAMIENTO DE TORREMOCHA DE JARAMA'),
        ));
    }

    /** El pago confirmado desde la factura (otro concepto) y el del extracto: los une la ficha. */
    public function testLaMismaFichaEsDuplicado(): void
    {
        $alvarez = $this->provider();

        $this->assertTrue(DuplicateEntryRule::isLikely(
            $this->entry('Semillero de octubre')->setProvider($alvarez),
            $this->entry('TRF. SEMILLEROS ALVAREZ')->setProvider($alvarez),
        ));
    }

    /** 33 € a la reprografía y 33 € al veterinario, en días seguidos: dos pagos. */
    public function testConceptoYFichaDistintosNoEsDuplicado(): void
    {
        $this->assertFalse(DuplicateEntryRule::isLikely(
            $this->entry('REPRODUCCIONES CABRERA pago tarjeta'),
            $this->entry('trf.hospital veterinario altai'),
        ));
        $this->assertFalse(DuplicateEntryRule::isLikely(
            $this->entry('Pago')->setProvider($this->provider()),
            $this->entry('Otro pago')->setProvider($this->provider()),
        ));
    }

    /** Un concepto que sólo son cifras no dice nada: no basta para llamarlo duplicado. */
    public function testUnConceptoSinLetrasNoCuenta(): void
    {
        $this->assertFalse(DuplicateEntryRule::isLikely($this->entry('0013104153'), $this->entry('0013104153')));
    }

    private function entry(string $concept): AccountEntry
    {
        $entry = (new AccountEntry())->setConcept($concept)->setAmount('-33.00');
        (new \ReflectionProperty(AccountEntry::class, 'id'))->setValue($entry, $this->nextId++);

        return $entry;
    }

    private function provider(): Provider
    {
        $provider = (new Provider())->setName('Proveedor '.$this->nextId);
        (new \ReflectionProperty(Provider::class, 'id'))->setValue($provider, $this->nextId++);

        return $provider;
    }
}
