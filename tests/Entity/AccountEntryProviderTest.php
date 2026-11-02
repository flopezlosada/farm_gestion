<?php

namespace App\Tests\Entity;

use App\Entity\AccountEntry;
use App\Entity\Provider;
use PHPUnit\Framework\TestCase;

/**
 * El proveedor de un gasto como ficha: al ponerlo, el nombre que se enseña pasa a
 * ser el de la ficha; y un pago del banco que una factura explica coge su ficha si no
 * tenía.
 */
class AccountEntryProviderTest extends TestCase
{
    public function testLaFichaPoneElNombre(): void
    {
        $provider = (new Provider())->setName('Semilleros Álvarez S.L.');
        $entry = (new AccountEntry())->setProviderName('SEMILLEROS ALVAREZ');

        $entry->setProvider($provider);

        $this->assertSame($provider, $entry->getProvider());
        $this->assertSame('Semilleros Álvarez S.L.', $entry->getProviderName());

        $entry->setProvider(null);
        $this->assertNull($entry->getProvider());
        $this->assertSame('Semilleros Álvarez S.L.', $entry->getProviderName(), 'Quitar la ficha no borra el nombre.');
    }

    public function testElPagoCogeLaFichaDeLaFacturaSiNoTenia(): void
    {
        $provider = (new Provider())->setName('Granja de Ibai');
        $fromInvoice = (new AccountEntry())->setProvider($provider);
        $bank = (new AccountEntry())->setProviderName('trf. granja de ibai');

        $bank->completeFrom($fromInvoice);
        $this->assertSame($provider, $bank->getProvider());

        $other = (new Provider())->setName('Otro');
        $kept = (new AccountEntry())->setProvider($other);
        $kept->completeFrom($fromInvoice);
        $this->assertSame($other, $kept->getProvider(), 'Una ficha ya puesta no se pisa.');
    }
}
