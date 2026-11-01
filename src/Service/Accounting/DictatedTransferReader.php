<?php

namespace App\Service\Accounting;

use App\Entity\FinancialAccount;
use App\Entity\PendingTransfer;
use App\Entity\User;
use App\Service\Ai\GeminiClient;
use App\Service\Ai\GeminiException;

/**
 * Convierte un traspaso contado por voz o por escrito («he pasado 50 € de la caja del
 * local a la de la huerta») en un traspaso POR CONFIRMAR. Nunca en apuntes: eso lo
 * hace quien lo revisa en la web.
 *
 * Las cuentas se eligen SÓLO entre las activas: la respuesta de Gemini está atada a sus
 * claves en el esquema, así que no puede inventarse una. Lo que no entienda se queda
 * vacío y lo completa quien confirma.
 */
class DictatedTransferReader
{
    public const NONE = 'ninguna';

    /**
     * @param GeminiClient $gemini Cliente de Gemini.
     */
    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    /** Si se puede leer: sin Gemini no hay quien entienda lo dicho. */
    public function isAvailable(): bool
    {
        return $this->gemini->isConfigured();
    }

    /**
     * El traspaso que sale de lo dicho, sin guardar.
     *
     * @param list<array<string, mixed>> $parts    Lo dicho: el audio ({@see GeminiClient::filePart()}) y/o `['text' => …]`.
     * @param list<FinancialAccount>     $accounts Cuentas entre las que elegir.
     * @param \DateTimeImmutable         $today    Fecha si no se dice otra.
     * @param User                       $by       Quién lo cuenta.
     *
     * @throws GeminiException Si Gemini no ha contestado.
     */
    public function read(array $parts, array $accounts, \DateTimeImmutable $today, User $by): PendingTransfer
    {
        $byKey = [];
        foreach ($accounts as $account) {
            $byKey['a' . $account->getId()] = $account;
        }
        $keys = [...array_keys($byKey), self::NONE];

        $result = $this->gemini->generateJson([...$parts, ['text' => $this->prompt($accounts, $today)]], [
            'type' => 'OBJECT',
            'properties' => [
                'desde' => ['type' => 'STRING', 'enum' => $keys],
                'hasta' => ['type' => 'STRING', 'enum' => $keys],
                'importe' => ['type' => 'NUMBER'],
                'fecha' => ['type' => 'STRING'],
                'concepto' => ['type' => 'STRING'],
            ],
            'required' => ['desde', 'hasta'],
        ]);
        $data = $result['data'];

        $transfer = new PendingTransfer($by, GeminiClient::isoDate($data['fecha'] ?? null) ?? $today);
        $transfer->setAccounts($byKey[(string) ($data['desde'] ?? '')] ?? null, $byKey[(string) ($data['hasta'] ?? '')] ?? null);
        $transfer->setAmount(is_numeric($data['importe'] ?? null) ? (string) $data['importe'] : null);
        $transfer->setConcept(\is_string($data['concepto'] ?? null) ? $data['concepto'] : null);

        return $transfer;
    }

    /**
     * Las instrucciones: qué sacar y con qué criterios.
     *
     * @param list<FinancialAccount> $accounts Cuentas activas.
     * @param \DateTimeImmutable     $today    Hoy, para entender «ayer» o «el lunes».
     */
    private function prompt(array $accounts, \DateTimeImmutable $today): string
    {
        $list = implode("\n", array_map(
            static fn (FinancialAccount $a): string => sprintf('- a%d: %s (%s)', $a->getId(), $a->getName(), mb_strtolower($a->getKindLabel())),
            $accounts,
        ));

        return "Alguien de la asociación CSA Vega de Jarama cuenta que ha movido dinero de una cuenta o caja de la asociación a otra. Hoy es {$today->format('Y-m-d')}.\n"
            . "Cuentas y cajas de la asociación:\n{$list}\n"
            . 'En "desde", la clave de la cuenta de la que SALE el dinero; en "hasta", la de la que ENTRA. "' . self::NONE . '" si no lo dice o no es ninguna de la lista. '
            . 'En "importe", los euros como número (1.200 € es 1200, 50,5 € es 50.5). '
            . 'En "fecha", AAAA-MM-DD sólo si dice cuándo; si no, vacía. '
            . 'En "concepto", una frase corta con el porqué si lo dice («para gasoil del tractor»); si no, vacío.';
    }
}
