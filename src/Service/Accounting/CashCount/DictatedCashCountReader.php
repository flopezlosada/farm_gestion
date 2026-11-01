<?php

namespace App\Service\Accounting\CashCount;

use App\Entity\FinancialAccount;
use App\Service\Ai\GeminiClient;
use App\Service\Ai\GeminiException;

/**
 * Un arqueo DICHO en una nota de voz («caja de la huerta, cuatrocientos cincuenta y seis
 * con cincuenta, del treinta de octubre»), puesto por escrito como se escribiría al bot:
 * «caja Caja de la huerta 456,50 30/10/2026». Así sigue el mismo camino que un arqueo
 * escrito ({@see CashCountMessage}): las mismas comprobaciones y el mismo registro.
 *
 * Gemini sólo elige entre las cajas abiertas (la respuesta está atada a sus claves en el
 * esquema, no puede inventarse una) y dice el importe y la fecha. Lo que no entienda se
 * rechaza: un importe no se adivina. Y nada se registra sin que quien lo dijo confirme
 * lo entendido.
 */
class DictatedCashCountReader
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
     * El arqueo dicho, escrito como un arqueo escrito.
     *
     * @param list<array<string, mixed>> $parts    Lo dicho: el audio ({@see GeminiClient::filePart()}) y/o `['text' => …]`.
     * @param list<FinancialAccount>     $accounts Cajas abiertas entre las que elegir.
     * @param \DateTimeImmutable         $day      El día en que se dijo: si no dice otro, es el del recuento.
     *
     * @return string «caja <nombre de la caja> <importe con coma> <dd/mm/aaaa>».
     *
     * @throws GeminiException   Si Gemini no ha contestado.
     * @throws CashCountRejected Si no se entiende de qué caja es o cuánto había.
     */
    public function read(array $parts, array $accounts, \DateTimeImmutable $day): string
    {
        $byKey = [];
        foreach ($accounts as $account) {
            $byKey['a' . $account->getId()] = $account;
        }

        $result = $this->gemini->generateJson([...$parts, ['text' => $this->prompt($accounts, $day)]], [
            'type' => 'OBJECT',
            'properties' => [
                'caja' => ['type' => 'STRING', 'enum' => [...array_keys($byKey), self::NONE]],
                'importe' => ['type' => 'NUMBER'],
                'fecha' => ['type' => 'STRING'],
            ],
            'required' => ['caja'],
        ]);
        $data = $result['data'];

        $account = $byKey[(string) ($data['caja'] ?? '')] ?? null;
        if ($account === null) {
            throw new CashCountRejected('No he entendido de qué caja es. Dímelo con su nombre: «caja de la huerta, cuatrocientos con cincuenta».');
        }
        if (!is_numeric($data['importe'] ?? null)) {
            throw new CashCountRejected(sprintf('No he entendido cuánto había en %s. Dímelo otra vez, o escríbemelo: «caja huerta 400,56».', $account->getName()));
        }

        return sprintf(
            'caja %s %s %s',
            $account->getName(),
            number_format((float) $data['importe'], 2, ',', ''),
            (GeminiClient::isoDate($data['fecha'] ?? null) ?? $day)->format('d/m/Y'),
        );
    }

    /**
     * Las instrucciones: qué sacar y con qué criterios.
     *
     * @param list<FinancialAccount> $accounts Cajas abiertas.
     * @param \DateTimeImmutable     $day      El día en que se dijo, para entender «ayer» o «el viernes».
     */
    private function prompt(array $accounts, \DateTimeImmutable $day): string
    {
        $list = implode("\n", array_map(
            static fn (FinancialAccount $a): string => sprintf('- a%d: %s', $a->getId(), $a->getName()),
            $accounts,
        ));

        return "Alguien de la asociación CSA Vega de Jarama ha contado el dinero que hay en una caja de efectivo y dice cuánto ha contado. Lo dice el {$day->format('Y-m-d')}.\n"
            . "Cajas de efectivo:\n{$list}\n"
            . 'En "caja", la clave de la caja que ha contado; "' . self::NONE . '" si no lo dice o no es ninguna de la lista. '
            . 'En "importe", los euros contados como número, tal como los dice (cuatrocientos cincuenta y seis con cincuenta es 456.5; mil doscientos es 1200). Si no lo dice, no lo pongas. '
            . 'En "fecha", AAAA-MM-DD sólo si dice de qué día es el recuento; si no, vacía.';
    }
}
