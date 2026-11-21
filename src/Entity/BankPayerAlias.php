<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Quién es, en la app, quien aparece como ordenante de una transferencia en el banco.
 *
 * El banco corta el nombre del ordenante y a veces lo da sólo con los apellidos; y
 * hay quien paga por otra persona (un familiar sin ficha). Ningún parecido de nombres
 * lo resuelve, así que se aprende: cuando la tesorera asigna a mano una transferencia,
 * el ordenante queda ligado a esa familia y la próxima vez casa solo.
 *
 * Se guarda el ordenante normalizado (minúsculas, sin tildes, espacios simples), que
 * es como lo compara el cruce. Un ordenante es de una sola familia.
 *
 * @ORM\Table(name="bank_payer_alias")
 * @ORM\Entity(repositoryClass="App\Repository\BankPayerAliasRepository")
 */
class BankPayerAlias
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(name="payer_key", type="string", length=100, unique=true)
     */
    private string $payerKey;

    /**
     * Quien encabeza la familia que paga.
     *
     * @ORM\ManyToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="partner_id", nullable=false, onDelete="CASCADE")
     */
    private Partner $partner;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @param string  $payerKey el ordenante, ya normalizado
     * @param Partner $partner  la familia que paga; se guarda quien la encabeza
     */
    public function __construct(string $payerKey, Partner $partner)
    {
        $this->payerKey = $payerKey;
        $this->partner = $partner->getFamilyHead();
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Pasa a ser de otra familia: la tesorera corrigió a quién paga. */
    public function pointTo(Partner $partner): self
    {
        $this->partner = $partner->getFamilyHead();

        return $this;
    }

    /** Identificador del alias, null hasta que se guarda. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** El ordenante normalizado, tal como lo compara el cruce. */
    public function getPayerKey(): string
    {
        return $this->payerKey;
    }

    /** La persona de la familia a la que se liga el ordenante. */
    public function getPartner(): Partner
    {
        return $this->partner;
    }

    /** Cuándo se aprendió. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
