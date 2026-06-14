<?php

namespace App\Command;

use Doctrine\DBAL\Connection;

/**
 * Guarda técnica para comandos que mutan la BBDD: valida el NOMBRE de la base
 * de datos de la conexión activa ANTES de tocar nada, de modo que un
 * DATABASE_URL despistado (apuntando a la golden o a cualquier BBDD con datos
 * de referencia) aborte en seco en vez de corromper datos reales.
 *
 * Complementa a --force: el flag confirma la INTENCIÓN del operador, esto
 * verifica el HECHO (a qué BBDD está conectado de verdad). Hasta ahora la
 * única salvaguarda era procedimental ("acuérdate de exportar DATABASE_URL");
 * esta clase la convierte en técnica.
 */
final class DatabaseGuard
{
    /**
     * Fragmentos de nombre que identifican BBDD con datos de referencia
     * intocables (la golden db_prod_snapshot y cualquier prod futura).
     */
    private const PROTECTED_FRAGMENTS = ['prod', 'snapshot', 'golden'];

    private function __construct()
    {
    }

    /**
     * Aborta si la BBDD activa no parece un clon desechable: su nombre debe
     * contener alguno de los fragmentos permitidos. Para comandos que
     * destrozan datos a propósito (p. ej. la batería de verificación, que
     * parte PBS y borra WeeklyBaskets).
     *
     * @param Connection $connection       conexión activa de Doctrine
     * @param string[]   $allowedFragments fragmentos que legitiman el nombre (p. ej. 'play', 'battery')
     *
     * @throws \RuntimeException si el nombre de la BBDD no encaja con ningún fragmento
     */
    public static function assertDisposable(Connection $connection, array $allowedFragments): void
    {
        $db = (string) $connection->getDatabase();
        foreach ($allowedFragments as $fragment) {
            if (str_contains($db, $fragment)) {
                return;
            }
        }

        throw new \RuntimeException(sprintf(
            'La BBDD activa es "%s" y no parece un clon desechable (esperaba un nombre que contenga: %s). '
            . 'Crea un clon (bin/db-play-reset) y pasa su DATABASE_URL explícito.',
            $db,
            implode(', ', $allowedFragments)
        ));
    }

    /**
     * Aborta si la BBDD activa es la golden u otra protegida (nombre con
     * 'prod', 'snapshot' o 'golden'). Para comandos mutantes que sí pueden
     * correr sobre la sandbox o un clon, pero jamás sobre la fuente de verdad.
     *
     * @param Connection $connection conexión activa de Doctrine
     *
     * @throws \RuntimeException si la BBDD activa está protegida
     */
    public static function assertNotProtected(Connection $connection): void
    {
        $db = (string) $connection->getDatabase();
        foreach (self::PROTECTED_FRAGMENTS as $fragment) {
            if (str_contains($db, $fragment)) {
                throw new \RuntimeException(sprintf(
                    'La BBDD activa es "%s" (protegida: el nombre contiene "%s"). Este comando muta datos '
                    . 'y no debe correr contra una BBDD de referencia. Apunta DATABASE_URL a la sandbox o a un clon.',
                    $db,
                    $fragment
                ));
            }
        }
    }
}
