<?php

namespace App\Service\Accounting\OfficialNotice;

/**
 * Lo que certifica una cabecera Authentication-Results (RFC 8601) sobre las firmas
 * DKIM de un correo: qué dominios han firmado y la firma ha salido válida.
 *
 * QUIÉN LA ESCRIBE IMPORTA MÁS QUE LO QUE DICE. Cada servidor por el que pasa el
 * correo añade la suya ENCIMA de las que ya trae, así que la de más arriba es la del
 * servidor de nuestro buzón y las de debajo las pudo escribir cualquiera, también
 * quien suplanta al organismo. Sólo se lee la primera: quien llama le pasa ésa.
 *
 * Una cabecera típica:
 *   mx.ejemplo.net; dkim=pass (2048-bit key) header.d=correo.gob.es header.s=sel; spf=pass smtp.mailfrom=…
 */
final class AuthenticationResults
{
    private function __construct()
    {
    }

    /**
     * Dominios de las firmas DKIM que la cabecera da por válidas. Toma el dominio de
     * `header.d` y, si no viene, el de `header.i` (algunos servidores sólo ponen ése).
     * Los comentarios entre paréntesis se quitan antes, para que un «(dkim=pass)»
     * escrito como comentario no cuente.
     *
     * @param string|null $value Valor de la cabecera más alta, o null si el correo no la trae.
     *
     * @return list<string> Dominios en minúsculas; vacío si no hay ninguna firma válida.
     */
    public static function dkimPassDomains(?string $value): array
    {
        $resinfos = array_slice(explode(';', self::withoutComments((string) $value)), 1);

        return array_values(array_filter(array_map(self::passedDomain(...), $resinfos)));
    }

    /**
     * El dominio de un resultado `dkim=pass`, o null si es de otro método, no pasó
     * o no dice qué dominio firmó.
     *
     * @param string $resinfo Un resultado, entre dos «;».
     *
     * @return string|null El dominio en minúsculas.
     */
    private static function passedDomain(string $resinfo): ?string
    {
        if (preg_match('/^\s*dkim\s*=\s*pass(?:\s|$)/i', $resinfo) !== 1) {
            return null;
        }
        if (preg_match('/(?:^|\s)header\.d\s*=\s*"?([^\s";]+)"?/i', $resinfo, $d) === 1) {
            return rtrim(mb_strtolower($d[1]), '.');
        }
        if (preg_match('/(?:^|\s)header\.i\s*=\s*"?[^\s";@]*@([^\s";]+)"?/i', $resinfo, $i) === 1) {
            return rtrim(mb_strtolower($i[1]), '.');
        }

        return null;
    }

    /**
     * La cabecera sin comentarios (RFC 5322 los permite anidados) y en una línea.
     *
     * @param string $value Valor de la cabecera, quizá plegado en varias líneas.
     *
     * @return string El valor limpio.
     */
    private static function withoutComments(string $value): string
    {
        $clean = (string) preg_replace('/\s+/', ' ', $value);
        do {
            $clean = (string) preg_replace('/\([^()]*\)/', ' ', $clean, -1, $count);
        } while ($count > 0);

        return $clean;
    }
}
