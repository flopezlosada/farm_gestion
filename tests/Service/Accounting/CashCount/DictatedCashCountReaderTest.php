<?php

namespace App\Tests\Service\Accounting\CashCount;

use App\Entity\FinancialAccount;
use App\Service\Accounting\CashCount\CashCountMessage;
use App\Service\Accounting\CashCount\CashCountRejected;
use App\Service\Accounting\CashCount\DictatedCashCountReader;
use App\Service\Ai\GeminiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * De un arqueo dicho a un arqueo escrito: la caja sólo de la lista, el importe y la
 * fecha tal como se escribirían al bot, y lo que no se entiende se rechaza en vez de
 * adivinarlo. Lo escrito tiene que leerse igual que si lo hubiera tecleado una persona.
 */
class DictatedCashCountReaderTest extends TestCase
{
    /** @var list<array<string, mixed>> Cuerpos que ha recibido Gemini. */
    private array $sent = [];

    /** «Caja de la huerta, cuatrocientos cincuenta y seis con cincuenta», sin fecha: la del día en que se dijo. */
    public function testSinFechaEsDelDiaEnQueSeDijo(): void
    {
        $written = $this->reader(['caja' => 'a5', 'importe' => 456.5, 'fecha' => ''])->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-31'));

        $this->assertSame('caja Caja de la huerta 456,50 31/10/2026', $written);
        $message = CashCountMessage::parse($written, new \DateTimeImmutable('2026-10-31'));
        $this->assertSame('Caja de la huerta', $message->accountName);
        $this->assertSame(45650, $message->countedCents);
        $this->assertSame('2026-10-31', $message->date->format('Y-m-d'));
    }

    /**
     * Con fecha, esa; y un importe de miles o con decimales flotantes se escribe con
     * coma decimal y sin puntos de miles, que es lo que el arqueo escrito lee sin dudas.
     */
    public function testConFechaYMilesSeEscribeComoUnArqueoEscrito(): void
    {
        $written = $this->reader(['caja' => 'a4', 'importe' => 1234.499999, 'fecha' => '2026-10-30'])->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-31'));

        $this->assertSame('caja Caja local 1234,50 30/10/2026', $written);
        $this->assertSame(123450, CashCountMessage::parse($written, new \DateTimeImmutable('2026-10-31'))->countedCents);
    }

    /** Una caja vacía: «cero» es un importe, no la falta de él. */
    public function testCeroEsUnImporte(): void
    {
        $written = $this->reader(['caja' => 'a4', 'importe' => 0])->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-31'));

        $this->assertSame('caja Caja local 0,00 31/10/2026', $written);
    }

    /** Una fecha que no es AAAA-MM-DD no se interpreta: es la del día en que se dijo. */
    public function testUnaFechaQueNoLoEsEsLaDelDia(): void
    {
        $written = $this->reader(['caja' => 'a4', 'importe' => 10, 'fecha' => 'el viernes'])->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-31'));

        $this->assertStringEndsWith(' 31/10/2026', $written);
    }

    /** Gemini sólo puede contestar con las cajas dadas (o ninguna), y las ve en el texto con el día. */
    public function testGeminiEligeSoloEntreLasCajas(): void
    {
        $this->reader(['caja' => 'a4', 'importe' => 10])->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-31'));

        $request = $this->sent[0];
        $this->assertSame(['a4', 'a5', 'ninguna'], $request['generationConfig']['responseSchema']['properties']['caja']['enum']);
        $prompt = end($request['contents'][0]['parts'])['text'];
        $this->assertStringContainsString('a5: Caja de la huerta', $prompt);
        $this->assertStringContainsString('2026-10-31', $prompt);
    }

    /** Sin caja (o una clave que no está en la lista, que viene de fuera) no hay arqueo. */
    public function testSinCajaSeRechaza(): void
    {
        foreach (['ninguna', 'a99'] as $key) {
            try {
                $this->reader(['caja' => $key, 'importe' => 10])->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-31'));
                $this->fail('«' . $key . '» no es ninguna caja');
            } catch (CashCountRejected $e) {
                $this->assertStringContainsString('de qué caja es', $e->getMessage());
            }
        }
    }

    /** Sin importe no hay arqueo: un importe no se adivina. */
    public function testSinImporteSeRechaza(): void
    {
        $this->expectException(CashCountRejected::class);
        $this->expectExceptionMessage('No he entendido cuánto había en Caja de la huerta');

        $this->reader(['caja' => 'a5'])->read([['text' => 'x']], $this->accounts(), new \DateTimeImmutable('2026-10-31'));
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
    private function reader(array $answer): DictatedCashCountReader
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->sent[] = json_decode($options['body'], true);

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]],
            ]), ['http_code' => 200]);
        });

        return new DictatedCashCountReader(new GeminiClient($http, 'clave', ['modelo-a']));
    }
}
