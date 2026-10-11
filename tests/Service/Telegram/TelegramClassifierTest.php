<?php

namespace App\Tests\Service\Telegram;

use App\Entity\User;
use App\Service\Ai\GeminiClient;
use App\Service\Ai\GeminiException;
use App\Service\Telegram\Destination\TelegramDestination;
use App\Service\Telegram\Destination\TelegramOutcome;
use App\Service\Telegram\TelegramClassifier;
use App\Service\Telegram\TelegramInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * El clasificador elige SÓLO entre los candidatos, «ninguno» es una respuesta, y
 * dice si está seguro: sólo entonces se guarda sin preguntar.
 */
class TelegramClassifierTest extends TestCase
{
    /** @var list<array<string, mixed>> Cuerpos que ha recibido Gemini. */
    private array $sent = [];

    public function testDevuelveElDestinoQueEligeGemini(): void
    {
        $classifier = $this->classifier('harvest');

        $result = $classifier->classify($this->text('12 kilos de patata'), [$this->destination('invoice'), $this->destination('harvest')]);

        $this->assertSame('harvest', $result->destination?->key());
        $this->assertTrue($result->isClear());
    }

    /** Si duda, se sabe cuál cree, pero no se da por claro: el bot preguntará. */
    public function testSiDudaNoEstaClaro(): void
    {
        $result = $this->classifier('harvest', sure: false)->classify($this->text('30 de la troje'), [$this->destination('invoice'), $this->destination('harvest')]);

        $this->assertSame('harvest', $result->destination?->key());
        $this->assertFalse($result->isClear());
    }

    /** Si no dice nada de su seguridad, se toma como duda. */
    public function testSinSeguridadEsDuda(): void
    {
        $result = $this->classifier('harvest', sure: null)->classify($this->text('x'), [$this->destination('invoice'), $this->destination('harvest')]);

        $this->assertFalse($result->isClear());
    }

    public function testNingunoEsNull(): void
    {
        $result = $this->classifier(TelegramClassifier::NONE)->classify($this->text('hola'), [$this->destination('invoice'), $this->destination('harvest')]);

        $this->assertNull($result->destination);
        $this->assertFalse($result->isClear(), 'ni aunque diga que está seguro de que no es nada');
    }

    /** Gemini sólo puede contestar con las claves de los candidatos (o ninguno), y lee sus descripciones. */
    public function testLaRespuestaEstaAtadaALosCandidatos(): void
    {
        $this->classifier('invoice')->classify($this->text('ticket'), [$this->destination('invoice'), $this->destination('gallery')]);

        $body = $this->sent[0];
        $this->assertSame(['invoice', 'gallery', TelegramClassifier::NONE], $body['generationConfig']['responseSchema']['properties']['destino']['enum']);
        $this->assertSame(['destino', 'seguro'], $body['generationConfig']['responseSchema']['required']);
        $prompt = end($body['contents'][0]['parts'])['text'];
        $this->assertStringContainsString('Descripción de invoice', $prompt);
        $this->assertStringContainsString('«ticket»', $prompt);
    }

    /** Si Gemini se sale del guion con una clave que no existe, no se inventa destino. */
    public function testUnaClaveDesconocidaEsNull(): void
    {
        $result = $this->classifier('borrar_todo')->classify($this->text('x'), [$this->destination('invoice'), $this->destination('harvest')]);

        $this->assertNull($result->destination);
    }

    public function testSiGeminiNoContestaLoDice(): void
    {
        $gemini = new GeminiClient(new MockHttpClient(new MockResponse('', ['http_code' => 503])), 'clave', ['modelo-a']);

        $this->expectException(GeminiException::class);
        (new TelegramClassifier($gemini))->classify($this->text('x'), [$this->destination('invoice'), $this->destination('harvest')]);
    }

    /** Una foto se le enseña a Gemini; el texto solo, no lleva fichero. */
    public function testUnaFotoViajaComoFichero(): void
    {
        $input = TelegramInput::fromMessage(['date' => 1760000000, 'photo' => [['file_id' => 'f1', 'file_size' => 100]]]);
        $input->setDownloader(static function (): string {
            $path = tempnam(sys_get_temp_dir(), 'tg-test-');
            file_put_contents($path, "%PDF-1.4\n%%EOF\n");

            return $path;
        });

        $this->classifier('invoice')->classify($input, [$this->destination('invoice'), $this->destination('harvest')]);
        $input->cleanUp();

        $this->assertSame('application/pdf', $this->sent[0]['contents'][0]['parts'][0]['inline_data']['mime_type']);
    }

    /**
     * El clasificador con un Gemini que contesta lo dado.
     *
     * @param string    $answer Clave que elige.
     * @param bool|null $sure   Si dice que está seguro; null = no lo dice.
     */
    private function classifier(string $answer, ?bool $sure = true): TelegramClassifier
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer, $sure): MockResponse {
            $this->sent[] = json_decode($options['body'], true);

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(array_filter(['destino' => $answer, 'seguro' => $sure], static fn ($v) => $v !== null))]]]]],
            ]), ['http_code' => 200]);
        });

        return new TelegramClassifier(new GeminiClient($http, 'clave', ['modelo-a']));
    }

    private function text(string $text): TelegramInput
    {
        return TelegramInput::fromMessage(['date' => 1760000000, 'text' => $text]);
    }

    private function destination(string $key): TelegramDestination
    {
        return new class($key) implements TelegramDestination {
            public function __construct(private readonly string $key)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return ucfirst($this->key);
            }

            public function description(): string
            {
                return 'Descripción de ' . $this->key;
            }

            public function accepts(TelegramInput $input, User $user): bool
            {
                return true;
            }

            public function commit(TelegramInput $input, User $user): TelegramOutcome
            {
                return new TelegramOutcome('1', 'Hecho.');
            }

            public function undo(string $ref): ?string
            {
                return null;
            }

            public function help(User $user): ?string
            {
                return null;
            }
        };
    }
}
