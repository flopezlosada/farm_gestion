<?php

namespace App\Tests\Entity;

use App\Entity\Node;
use App\Entity\Partner;
use PHPUnit\Framework\TestCase;

/**
 * Unit de a qué direcciones va el listado de un nodo.
 *
 * Lista vacía y lista con gente son cosas distintas aquí: vacía significa "este
 * punto no tiene a nadie asignado, cae al ajuste general", y esa distinción es
 * la que decide si el listado sale o no sale.
 *
 * Con Partner de verdad y no con dobles: el getter del correo se llama
 * `getemail()` en minúsculas (herencia del código viejo) y configurar un mock
 * con el nombre canónico es pedir un fallo raro.
 */
class NodeSheetRecipientsTest extends TestCase
{
    public function testUnPuntoNuevoNoTieneDestinatarios(): void
    {
        $this->assertSame([], (new Node())->sheetRecipientEmails());
    }

    public function testDevuelveElCorreoDeCadaPersonaAsignada(): void
    {
        $node = (new Node())
            ->addSheetRecipient($this->partner('coordina@csavegadejarama.org'))
            ->addSheetRecipient($this->partner('reparto@csavegadejarama.org'));

        $this->assertSame(
            ['coordina@csavegadejarama.org', 'reparto@csavegadejarama.org'],
            $node->sheetRecipientEmails(),
        );
    }

    /**
     * Una ficha sin correo se descarta en vez de tumbar el envío: es un dato que
     * puede faltar, y quien sí lo tiene debe recibir su listado igual.
     */
    public function testUnaFichaSinCorreoNoRompeElResto(): void
    {
        $node = (new Node())
            ->addSheetRecipient(new Partner())
            ->addSheetRecipient($this->partner('reparto@csavegadejarama.org'));

        $this->assertSame(['reparto@csavegadejarama.org'], $node->sheetRecipientEmails());
    }

    /**
     * Si NINGUNA de las personas asignadas tiene correo, el punto se queda como
     * si no tuviera a nadie y el listado cae al ajuste general. Lo contrario
     * —una lista con huecos— haría creer al comando que ya tiene destinatarios.
     */
    public function testSiNingunaTieneCorreoElPuntoSeQuedaSinDestinatarios(): void
    {
        $node = (new Node())->addSheetRecipient(new Partner());

        $this->assertSame([], $node->sheetRecipientEmails());
    }

    public function testAsignarDosVecesALaMismaPersonaNoLaDuplica(): void
    {
        $partner = $this->partner('coordina@csavegadejarama.org');
        $node = (new Node())->addSheetRecipient($partner)->addSheetRecipient($partner);

        $this->assertCount(1, $node->sheetRecipientEmails());
    }

    public function testSeLePuedeQuitarElListadoAAlguien(): void
    {
        $partner = $this->partner('coordina@csavegadejarama.org');
        $node = (new Node())->addSheetRecipient($partner);

        $node->removeSheetRecipient($partner);

        $this->assertSame([], $node->sheetRecipientEmails());
    }

    /**
     * @param string $email Correo de la ficha.
     */
    private function partner(string $email): Partner
    {
        $partner = new Partner();
        $partner->setemail($email);

        return $partner;
    }
}
