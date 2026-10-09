<?php

namespace App\Tests\Service\Telegram;

use App\Service\Telegram\TelegramApiException;
use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramInput;
use PHPUnit\Framework\TestCase;

/**
 * Cómo se entiende cada clase de mensaje de Telegram, con la forma real que tienen:
 * de eso depende a qué destinos se le ofrece.
 */
class TelegramInputTest extends TestCase
{
    /** Telegram manda cada foto en varios tamaños, de menor a mayor: vale la mayor. */
    public function testUnaFotoSeQuedaConLaCopiaMasGrandeYSuPie(): void
    {
        $input = TelegramInput::fromMessage([
            'date' => 1760000000,
            'caption' => ' ticket gasolina ',
            'photo' => [
                ['file_id' => 'peque', 'file_size' => 1200, 'width' => 90, 'height' => 120],
                ['file_id' => 'grande', 'file_size' => 180000, 'width' => 960, 'height' => 1280],
            ],
        ]);

        $this->assertSame(TelegramInput::PHOTO, $input->kind);
        $this->assertSame('grande', $input->fileId);
        $this->assertSame(180000, $input->fileSize);
        $this->assertSame('ticket gasolina', $input->text);
        $this->assertStringStartsWith('foto-telegram-', $input->fileName);
    }

    public function testUnDocumentoConservaSuNombre(): void
    {
        $input = TelegramInput::fromMessage([
            'date' => 1760000000,
            'document' => ['file_id' => 'doc1', 'file_name' => 'Movistar 09-2026.pdf', 'mime_type' => 'application/pdf', 'file_size' => 51234],
        ]);

        $this->assertSame(TelegramInput::DOCUMENT, $input->kind);
        $this->assertSame('Movistar 09-2026.pdf', $input->fileName);
        $this->assertSame('', $input->text);
    }

    /** Un documento reenviado puede venir sin nombre: no debe romper nada. */
    public function testUnDocumentoSinNombreNiTamanoTambienVale(): void
    {
        $input = TelegramInput::fromMessage(['date' => 1760000000, 'document' => ['file_id' => 'doc2']]);

        $this->assertSame('documento', $input->fileName);
        $this->assertSame(0, $input->fileSize);
    }

    public function testUnaNotaDeVozEsVoz(): void
    {
        $input = TelegramInput::fromMessage(['date' => 1760000000, 'voice' => ['file_id' => 'v1', 'duration' => 7, 'mime_type' => 'audio/ogg', 'file_size' => 20000]]);

        $this->assertSame(TelegramInput::VOICE, $input->kind);
        $this->assertSame('audio/ogg', $input->mimeType);
    }

    public function testUnTextoEsTextoYSinFichero(): void
    {
        $input = TelegramInput::fromMessage(['date' => 1760000000, 'text' => 'hola']);

        $this->assertSame(TelegramInput::TEXT, $input->kind);
        $this->assertFalse($input->hasFile());
    }

    /** Una pegatina, una ubicación o un mensaje vacío no son nada que el bot reciba. */
    public function testLoQueNoSabeRecibirDaNull(): void
    {
        $this->assertNull(TelegramInput::fromMessage(['date' => 1760000000, 'sticker' => ['file_id' => 's1']]));
        $this->assertNull(TelegramInput::fromMessage(['date' => 1760000000, 'text' => '   ']));
    }

    /** El fichero se descarga una vez aunque lo pidan el clasificador y el destino. */
    public function testElFicheroSeDescargaUnaSolaVez(): void
    {
        $input = TelegramInput::fromMessage(['date' => 1760000000, 'document' => ['file_id' => 'doc1', 'file_size' => 10]]);
        $calls = 0;
        $input->setDownloader(static function (string $id) use (&$calls): string {
            ++$calls;
            $path = tempnam(sys_get_temp_dir(), 'tg-test-');
            file_put_contents($path, 'x');

            return $path;
        });

        $path = $input->localPath();
        $this->assertSame($path, $input->localPath());
        $this->assertSame(1, $calls);

        $input->cleanUp();
        $this->assertFileDoesNotExist($path);
    }

    /** Lo que Telegram no deja descargar ni se intenta. */
    public function testUnFicheroDeMasDe20MbNiSeIntentaDescargar(): void
    {
        $input = TelegramInput::fromMessage(['date' => 1760000000, 'document' => ['file_id' => 'big', 'file_size' => TelegramBotApi::MAX_DOWNLOAD_BYTES + 1]]);
        $input->setDownloader(function (): string {
            $this->fail('No debía descargarse.');
        });

        $this->expectException(TelegramApiException::class);
        $input->localPath();
    }
}
