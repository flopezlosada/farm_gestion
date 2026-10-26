<?php

namespace App\Tests\Service\Accounting;

use App\Entity\FinancialAccount;
use App\Entity\User;
use App\Service\Accounting\DictatedTransferReader;
use App\Service\Ai\GeminiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * De lo contado al traspaso por confirmar: las cuentas sólo de la lista, y lo que no
 * se entiende se queda vacío para que lo complete quien confirma, en vez de inventarlo.
 */
class DictatedTransferReaderTest extends TestCase
{
    /** @var list<array<string, mixed>> Cuerpos que ha recibido Gemini. */
    private array $sent = [];

    /** «He pasado 50 € de la caja del local a la de la huerta». */
    public function testElCasoNormal(): void
    {
        $transfer = $this->reader([
            'desde' => 'a4', 'hasta' => 'a5', 'importe' => 50, 'fecha' => '2026-10-24', 'concepto' => 'Para gasoil del tractor',
        ])->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-26'), new User());

        $this->assertSame('Caja local', $transfer->getFrom()?->getName());
        $this->assertSame('Caja de la huerta', $transfer->getTo()?->getName());
        $this->assertSame('50.00', $transfer->getAmount());
        $this->assertSame('2026-10-24', $transfer->getDate()->format('Y-m-d'));
        $this->assertSame('Para gasoil del tractor', $transfer->getConcept());
        $this->assertFalse($transfer->isConfirmed(), 'el bot nunca toca el libro');
    }

    /** Gemini sólo puede contestar con las cuentas dadas (o ninguna), y las ve en el texto. */
    public function testGeminiEligeSoloEntreLasCuentas(): void
    {
        $this->reader(['desde' => 'ninguna', 'hasta' => 'ninguna'])
            ->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-26'), new User());

        $request = $this->sent[0];
        $this->assertSame(['a4', 'a5', 'ninguna'], $request['generationConfig']['responseSchema']['properties']['desde']['enum']);
        $this->assertStringContainsString('a5: Caja de la huerta', end($request['contents'][0]['parts'])['text']);
    }

    /**
     * Lo que no se entiende se queda vacío: misma cuenta en los dos lados (no es un
     * traspaso), importe que no es positivo, fecha que no lo es, concepto vacío.
     */
    public function testLoQueNoSeEntiendeSeQuedaVacio(): void
    {
        $transfer = $this->reader([
            'desde' => 'a4', 'hasta' => 'a4', 'importe' => -50, 'fecha' => 'el lunes', 'concepto' => '  ',
        ])->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-26'), new User());

        $this->assertSame('Caja local', $transfer->getFrom()?->getName());
        $this->assertNull($transfer->getTo());
        $this->assertNull($transfer->getAmount());
        $this->assertSame('2026-10-26', $transfer->getDate()->format('Y-m-d'));
        $this->assertNull($transfer->getConcept());
    }

    /** Una clave que no está en la lista (no debería, pero viene de fuera) no es ninguna cuenta. */
    public function testUnaClaveDesconocidaNoEsNingunaCuenta(): void
    {
        $transfer = $this->reader(['desde' => 'a99', 'hasta' => 'a5', 'importe' => 12.5])
            ->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-26'), new User());

        $this->assertNull($transfer->getFrom());
        $this->assertSame('12.50', $transfer->getAmount());
    }

    /**
     * Las dos cajas, con los ids que tienen en la base de verdad.
     *
     * @return list<FinancialAccount>
     */
    private function accounts(): array
    {
        return [$this->account(4, 'Caja local'), $this->account(5, 'Caja de la huerta')];
    }

    private function account(int $id, string $name): FinancialAccount
    {
        $account = (new FinancialAccount())->setName($name)->setKind(FinancialAccount::KIND_CASH);
        (new \ReflectionProperty(FinancialAccount::class, 'id'))->setValue($account, $id);

        return $account;
    }

    /**
     * @param array<string, mixed> $answer Lo que contesta Gemini.
     */
    private function reader(array $answer): DictatedTransferReader
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->sent[] = json_decode($options['body'], true);

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]],
            ]), ['http_code' => 200]);
        });

        return new DictatedTransferReader(new GeminiClient($http, 'clave', ['modelo-a']));
    }
}
