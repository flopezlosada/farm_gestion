<?php

namespace App\Tests\Entity;

use App\Entity\Node;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit de a qué direcciones va el listado de un nodo.
 *
 * Lista vacía y null son cosas distintas aquí: vacía significa "este nodo no
 * tiene a nadie asignado, cae al ajuste general", y esa distinción es la que
 * decide si el listado sale o no sale.
 */
class NodeSheetRecipientsTest extends TestCase
{
    public function testUnNodoNuevoNoTieneDestinatarios(): void
    {
        $this->assertSame([], (new Node())->sheetRecipientEmails());
    }

    public function testDevuelveElCorreoDeCadaPersonaAsignada(): void
    {
        $node = (new Node())
            ->addSheetRecipient($this->user('coordina@csavegadejarama.org'))
            ->addSheetRecipient($this->user('reparto@csavegadejarama.org'));

        $this->assertSame(
            ['coordina@csavegadejarama.org', 'reparto@csavegadejarama.org'],
            $node->sheetRecipientEmails(),
        );
    }

    /**
     * Una cuenta sin correo se descarta en vez de tumbar el envío: es un dato que
     * puede faltar, y quien sí lo tiene debe recibir su listado igual.
     */
    public function testUnaCuentaSinCorreoNoRompeElResto(): void
    {
        $node = (new Node())
            ->addSheetRecipient($this->user(null))
            ->addSheetRecipient($this->user('reparto@csavegadejarama.org'));

        $this->assertSame(['reparto@csavegadejarama.org'], $node->sheetRecipientEmails());
    }

    /**
     * Si TODAS las cuentas asignadas están sin correo, el nodo se queda como si
     * no tuviera a nadie y el listado cae al ajuste general. Lo contrario —una
     * lista con huecos— haría creer al comando que ya tiene destinatarios.
     */
    public function testSiNingunaTieneCorreoElNodoSeQuedaSinDestinatarios(): void
    {
        $node = (new Node())->addSheetRecipient($this->user(null));

        $this->assertSame([], $node->sheetRecipientEmails());
    }

    public function testAsignarDosVecesALaMismaPersonaNoLaDuplica(): void
    {
        $user = $this->user('coordina@csavegadejarama.org');
        $node = (new Node())->addSheetRecipient($user)->addSheetRecipient($user);

        $this->assertCount(1, $node->sheetRecipientEmails());
    }

    public function testSeLePuedeQuitarElListadoAAlguien(): void
    {
        $user = $this->user('coordina@csavegadejarama.org');
        $node = (new Node())->addSheetRecipient($user);

        $node->removeSheetRecipient($user);

        $this->assertSame([], $node->sheetRecipientEmails());
    }

    /**
     * @param string|null $email Correo de la cuenta, o null si no tiene.
     */
    private function user(?string $email): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);

        return $user;
    }
}
