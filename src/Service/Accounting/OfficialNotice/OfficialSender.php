<?php

namespace App\Service\Accounting\OfficialNotice;

/**
 * Un organismo que avisa por correo de que hay una notificación electrónica para la
 * asociación: la dirección desde la que escribe y los dominios con los que firma.
 *
 * La dirección sola no prueba nada (el remitente de un correo se escribe a mano); lo
 * que prueba que el correo es suyo es la firma DKIM de su dominio. Por eso no se
 * puede construir sin al menos un dominio de firma: un remitente sin ella se daría
 * por bueno con cualquier correo que lo suplantara.
 */
final class OfficialSender
{
    /** Dirección del remitente, en minúsculas. */
    public readonly string $address;

    /** @var non-empty-list<string> Dominios de firma aceptados, en minúsculas. */
    public readonly array $dkimDomains;

    /**
     * @param string       $address     Dirección del remitente.
     * @param string       $agency      Nombre del organismo, como se enseña en el aviso.
     * @param list<string> $dkimDomains Dominios de firma DKIM que se aceptan (o un subdominio suyo).
     *
     * @throws \InvalidArgumentException Si falta la dirección, el organismo o los dominios de firma.
     */
    public function __construct(string $address, public readonly string $agency, array $dkimDomains)
    {
        $address = mb_strtolower(trim($address));
        $domains = array_values(array_unique(array_filter(array_map(
            static fn (string $domain): string => rtrim(mb_strtolower(trim($domain)), '.'),
            $dkimDomains,
        ))));
        if ($address === '' || trim($agency) === '' || $domains === []) {
            throw new \InvalidArgumentException(sprintf('Remitente oficial mal configurado: «%s» necesita organismo y al menos un dominio de firma.', $address));
        }
        $this->address = $address;
        $this->dkimDomains = $domains;
    }

    /**
     * Si un dominio de firma es uno de los suyos o un subdominio de uno de ellos.
     * «correo.aeat.es» vale para «aeat.es»; «aeat.es.otro.com» y «falsoaeat.es», no.
     *
     * @param string $domain Dominio de la firma (d=), tal cual lo certifica el servidor.
     *
     * @return bool true si la firma es del organismo.
     */
    public function signs(string $domain): bool
    {
        $domain = rtrim(mb_strtolower(trim($domain)), '.');

        return $domain !== '' && array_filter(
            $this->dkimDomains,
            static fn (string $own): bool => $domain === $own || str_ends_with($domain, '.' . $own),
        ) !== [];
    }
}
