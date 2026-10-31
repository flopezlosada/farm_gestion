<?php

namespace App\Service\Accounting\OfficialNotice;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Los organismos cuyos avisos de notificación se esperan en el buzón. La lista vive
 * en la configuración (`app.official_notice_senders`) y no en el código: cuando
 * llegue el primer aviso de un organismo nuevo, se añade allí con el dominio de
 * firma que traiga.
 */
class OfficialSenders
{
    /** @var array<string, OfficialSender> Por dirección, en minúsculas. */
    private readonly array $byAddress;

    /**
     * @param array<string, array{agency: string, dkim: list<string>}> $senders Remitentes por dirección.
     *
     * @throws \InvalidArgumentException Si algún remitente está mal configurado.
     */
    public function __construct(
        #[Autowire('%app.official_notice_senders%')]
        array $senders,
    ) {
        $list = array_map(
            static fn (string $address, array $sender): OfficialSender => new OfficialSender($address, (string) ($sender['agency'] ?? ''), (array) ($sender['dkim'] ?? [])),
            array_keys($senders),
            array_values($senders),
        );
        $this->byAddress = array_combine(
            array_map(static fn (OfficialSender $s): string => $s->address, $list),
            $list,
        );
    }

    /**
     * El organismo que escribe desde esa dirección, sin distinguir mayúsculas.
     *
     * @param string|null $address Dirección del remitente (From) del correo.
     *
     * @return OfficialSender|null El organismo, o null si no es un remitente oficial.
     */
    public function find(?string $address): ?OfficialSender
    {
        return $this->byAddress[mb_strtolower(trim((string) $address))] ?? null;
    }
}
