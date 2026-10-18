<?php

namespace App\Service\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cifra las copias de seguridad antes de que salgan del servidor.
 *
 * POR QUÉ UNA "SEALED BOX" (caja sellada de libsodium): cifra con la clave
 * PÚBLICA y sólo la privada descifra. En el servidor vive únicamente la pública,
 * así que quien entre en el hosting puede generar copias nuevas pero no leer
 * ninguna de las que ya están fuera. La privada la guarda la junta, lejos del
 * servidor.
 *
 * Las claves viajan en base64. Basta con guardar la PRIVADA: la pública se deriva
 * de ella ({@see self::open()}).
 */
class SealedBox
{
    /**
     * @param string $publicKey Clave pública en base64 (vacía = copia apagada).
     */
    public function __construct(
        #[Autowire('%env(BACKUP_PUBLIC_KEY)%')]
        private readonly string $publicKey,
    ) {
    }

    /** Si PHP trae libsodium. Sin ella no hay cifrado posible. */
    public static function isAvailable(): bool
    {
        return \function_exists('sodium_crypto_box_seal');
    }

    /** Si hay clave pública con la que cifrar. */
    public function isConfigured(): bool
    {
        return trim($this->publicKey) !== '';
    }

    /**
     * Cifra un contenido para la clave pública configurada.
     *
     * @param string $plain Contenido en claro.
     *
     * @return string Contenido cifrado (binario).
     *
     * @throws \RuntimeException Si no hay libsodium o la clave no es válida.
     */
    public function seal(string $plain): string
    {
        return sodium_crypto_box_seal($plain, self::decodeKey($this->publicKey, 'pública'));
    }

    /**
     * Descifra una copia con la clave privada.
     *
     * @param string $sealed    Contenido cifrado.
     * @param string $secretKey Clave privada en base64.
     *
     * @return string Contenido en claro.
     *
     * @throws \RuntimeException Si la clave no es válida o no es la que cifró la copia.
     */
    public static function open(string $sealed, string $secretKey): string
    {
        $secret = self::decodeKey($secretKey, 'privada');
        $keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $secret,
            sodium_crypto_box_publickey_from_secretkey($secret),
        );

        $plain = sodium_crypto_box_seal_open($sealed, $keyPair);
        if ($plain === false) {
            throw new \RuntimeException('No se puede descifrar: la clave privada no es la de esta copia, o el fichero está dañado.');
        }

        return $plain;
    }

    /**
     * Pasa una clave de base64 a binario comprobando que tiene el tamaño de una
     * clave de caja. Las dos (pública y privada) miden lo mismo.
     *
     * @param string $base64 Clave en base64.
     * @param string $which  "pública" o "privada", para el mensaje.
     *
     * @throws \RuntimeException Si no hay libsodium o la clave no es válida.
     */
    private static function decodeKey(string $base64, string $which): string
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('Este PHP no trae libsodium (ext-sodium): no se puede cifrar ni descifrar.');
        }

        $key = base64_decode(trim($base64), true);
        if ($key === false || \strlen($key) !== \SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new \RuntimeException(sprintf('La clave %s no es válida: tiene que ser base64 de %d bytes.', $which, \SODIUM_CRYPTO_BOX_PUBLICKEYBYTES));
        }

        return $key;
    }
}
