<?php

namespace App\Tests\Service\Telegram;

use App\Controller\TelegramWebhookController;
use App\Service\Telegram\TelegramApiException;
use App\Service\Telegram\TelegramBotApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La API del bot y su webhook: lo que importa es que el token no se escape a un
 * log y que el webhook no exista para quien no traiga el secreto.
 */
class TelegramBotApiTest extends TestCase
{
    private const TOKEN = '123456:TOKEN-SECRETO';

    public function testSinTokenElBotEstaApagadoYNoLlamaANadie(): void
    {
        $http = new MockHttpClient([]);
        $api = new TelegramBotApi($http, '  ');

        $this->assertFalse($api->isEnabled());
        $this->expectException(TelegramApiException::class);
        $api->sendMessage('1', 'hola');
    }

    /**
     * El cliente HTTP mete la URL (con el token dentro) en el mensaje de sus
     * errores. La excepción que sale de aquí no debe llevarla ni encadenarla.
     */
    public function testUnFalloDeConexionNoDejaElTokenEnLaExcepcion(): void
    {
        $api = new TelegramBotApi(new MockHttpClient(static function (string $method, string $url): MockResponse {
            throw new TransportException(sprintf('Idle timeout reached for "%s".', $url));
        }), self::TOKEN);

        try {
            $api->sendMessage('1', 'hola');
            $this->fail('Debía fallar.');
        } catch (TelegramApiException $e) {
            $this->assertStringNotContainsString('TOKEN-SECRETO', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testTelegramExplicaElRechazoYSeRespeta(): void
    {
        $api = new TelegramBotApi(new MockHttpClient(new MockResponse('{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}', ['http_code' => 403])), self::TOKEN);

        $this->expectExceptionMessage('bot was blocked by the user');
        $api->sendMessage('1', 'hola');
    }

    /** El secreto del webhook cumple lo que exige Telegram: [A-Za-z0-9_-], hasta 256. */
    public function testElSecretoDelWebhookTieneUnFormatoQueTelegramAcepta(): void
    {
        $secret = (new TelegramBotApi(new MockHttpClient([]), self::TOKEN))->webhookSecret();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,256}$/', $secret);
        $this->assertNotSame($secret, (new TelegramBotApi(new MockHttpClient([]), 'otro:token'))->webhookSecret());
    }

    public function testElWebhookConElSecretoBuenoContestaYDejaElMensajeParaDespues(): void
    {
        $api = new TelegramBotApi(new MockHttpClient([]), self::TOKEN);
        $request = Request::create('/telegram/webhook', 'POST', [], [], [], [], '{"update_id":7,"message":{"text":"hola"}}');
        $request->headers->set(TelegramWebhookController::SECRET_HEADER, $api->webhookSecret());

        $response = (new TelegramWebhookController($api))->webhook($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(7, $request->attributes->get(TelegramWebhookController::UPDATE_ATTRIBUTE)['update_id']);
    }

    public function testElWebhookSinSecretoNoExiste(): void
    {
        $api = new TelegramBotApi(new MockHttpClient([]), self::TOKEN);
        $request = Request::create('/telegram/webhook', 'POST', [], [], [], [], '{"update_id":7}');
        $request->headers->set(TelegramWebhookController::SECRET_HEADER, 'adivinado');

        $this->expectException(NotFoundHttpException::class);
        (new TelegramWebhookController($api))->webhook($request);
    }

    /** Sin bot configurado no vale ni el secreto «vacío». */
    public function testElWebhookSinBotNoExiste(): void
    {
        $api = new TelegramBotApi(new MockHttpClient([]), '');
        $request = Request::create('/telegram/webhook', 'POST', [], [], [], [], '{}');
        $request->headers->set(TelegramWebhookController::SECRET_HEADER, $api->webhookSecret());

        $this->expectException(NotFoundHttpException::class);
        (new TelegramWebhookController($api))->webhook($request);
    }
}
