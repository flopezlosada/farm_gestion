<?php

namespace App\Tests\Service\Telegram;

use App\Entity\User;
use App\Form\UserType;
use App\Service\Telegram\Destination\InvoiceDestination;
use App\Service\Telegram\TelegramPermissions;
use App\Service\Telegram\TelegramSubmissionRouter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Los permisos del bot con los apartados de verdad: que cada uno cae en un área de
 * la ficha de la cuenta (si no, su casilla no saldría en ningún sitio) y quién
 * puede usar cada uno.
 */
class TelegramPermissionsTest extends KernelTestCase
{
    /** Un apartado con un área que no existe en la ficha no se podría asignar a nadie. */
    public function testCadaApartadoCaeEnUnAreaDeLaFicha(): void
    {
        $router = static::getContainer()->get(TelegramSubmissionRouter::class);
        $admin = (new User())->setRoles(['ROLE_ADMIN']);

        $destinations = $router->allowed($admin);
        $this->assertNotEmpty($destinations, 'admin tiene que ver todos los apartados');
        foreach ($destinations as $destination) {
            $this->assertArrayHasKey(
                $destination->section(),
                UserType::SECTIONS,
                sprintf('El apartado «%s» dice ser del área «%s», que no está en UserType::SECTIONS.', $destination->key(), $destination->section()),
            );
        }
    }

    public function testElPermisoSaleDeLaClave(): void
    {
        $invoices = static::getContainer()->get(InvoiceDestination::class);

        $this->assertSame('ROLE_BOT_INVOICE', TelegramPermissions::roleFor($invoices));
    }

    /**
     * Con su permiso, sí; sin él, no, aunque tenga acceso a la contabilidad en la
     * web; admin, siempre.
     */
    public function testQuienPuedeUsarUnApartado(): void
    {
        $permissions = static::getContainer()->get(TelegramPermissions::class);
        $invoices = static::getContainer()->get(InvoiceDestination::class);

        $this->assertTrue($permissions->allows((new User())->setRoles(['ROLE_BOT_INVOICE']), $invoices));
        $this->assertFalse($permissions->allows((new User())->setRoles(['ROLE_GESTION_CONTABILIDAD_EDIT']), $invoices));
        $this->assertFalse($permissions->allows(new User(), $invoices));
        $this->assertTrue($permissions->allows((new User())->setRoles(['ROLE_ADMIN']), $invoices));
    }
}
