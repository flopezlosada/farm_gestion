<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un efecto que ya se ha producido y NO debe producirse dos veces.
 *
 * Es deliberadamente genérica: no habla de correos ni de tareas concretas. Un
 * efecto es cualquier cosa irreversible hacia fuera del sistema — un email, un
 * SMS, una orden de cobro, un fichero depositado por FTP, una llamada a una API
 * que factura por operación. Lo único que el sistema necesita saber es "esto ya
 * está hecho", y para eso basta con una clave.
 *
 * La clave tiene tres partes, y el índice único sobre ellas ES el mecanismo:
 *
 * - `kind`: qué clase de efecto ("pickup_reminder", "sepa_charge"…).
 * - `reference`: sobre qué o quién ("partner-76", "invoice-2026-08"…). Cuando el
 *   efecto es único por ejecución y no por destinatario, vale una referencia
 *   fija.
 * - `occurredOn`: la fecha de negocio a la que corresponde, no el instante en
 *   que se emitió. Es lo que hace que "el recordatorio del reparto del 28 de
 *   agosto" sea distinto del de la semana siguiente.
 *
 * POR QUÉ NO BASTA UN SELLO POR TAREA Y DÍA. Si el envío de cuarenta avisos se
 * cae en el tercero, un sello puesto al principio deja a treinta y siete
 * personas sin aviso para siempre, y puesto al final hace que el reintento
 * repita los tres primeros. La granularidad tiene que ser la del efecto, no la
 * de la tarea.
 *
 * Y por qué un índice único y no una comprobación en código: los `if` pierden
 * las carreras. Dos procesos pueden comprobar a la vez que un aviso no se ha
 * mandado y mandarlo los dos; contra el índice, el segundo choca. Hay
 * precedente en casa con los PartnerDeliveryShift duplicados por doble envío de
 * un formulario, que se cerraron con una restricción de unicidad.
 *
 * La escribe {@see \App\Service\Cron\EffectLedger}.
 *
 * @ORM\Table(
 *     name="emitted_effect",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="UNIQ_emitted_effect_key", columns={"kind", "reference", "occurred_on"})},
 *     indexes={@ORM\Index(name="IDX_emitted_effect_emitted_at", columns={"emitted_at"})}
 * )
 * @ORM\Entity(repositoryClass="App\Repository\EmittedEffectRepository")
 */
class EmittedEffect
{
    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * Clase de efecto. La declara quien lo emite; el guardián no interpreta el
     * valor.
     *
     * @ORM\Column(name="kind", type="string", length=60)
     */
    private string $kind = '';

    /**
     * A qué o a quién se refiere el efecto.
     *
     * @ORM\Column(name="reference", type="string", length=100)
     */
    private string $reference = '';

    /**
     * Fecha de negocio del efecto (el día del reparto avisado, el mes cobrado…),
     * NO el instante de emisión: ése es {@see self::$emittedAt}.
     *
     * @ORM\Column(name="occurred_on", type="date_immutable")
     */
    private \DateTimeImmutable $occurredOn;

    /**
     * Destino concreto, si lo hay: una dirección de correo, un IBAN, una URL.
     * No forma parte de la clave —el mismo efecto no debe repetirse aunque
     * cambie el destino— y sirve para auditar después ("¿a qué dirección se
     * mandó?").
     *
     * @ORM\Column(name="target", type="string", length=255, nullable=true)
     */
    private ?string $target = null;

    /**
     * Cuándo se apuntó el efecto. Con este dato se purga la tabla por
     * antigüedad, igual que se purga usage_hit.
     *
     * @ORM\Column(name="emitted_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $emittedAt;

    public function __construct()
    {
        $this->occurredOn = new \DateTimeImmutable('today');
        $this->emittedAt = new \DateTimeImmutable();
    }

    /**
     * @return int|null Identificador autogenerado.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string Clase de efecto.
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @param string $kind Clase de efecto.
     */
    public function setKind(string $kind): self
    {
        $this->kind = $kind;
        return $this;
    }

    /**
     * @return string Referencia del efecto.
     */
    public function getReference(): string
    {
        return $this->reference;
    }

    /**
     * @param string $reference Referencia del efecto.
     */
    public function setReference(string $reference): self
    {
        $this->reference = $reference;
        return $this;
    }

    /**
     * @return \DateTimeImmutable Fecha de negocio del efecto.
     */
    public function getOccurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    /**
     * @param \DateTimeImmutable $occurredOn Fecha de negocio del efecto.
     */
    public function setOccurredOn(\DateTimeImmutable $occurredOn): self
    {
        $this->occurredOn = $occurredOn;
        return $this;
    }

    /**
     * @return string|null Destino del efecto, o null.
     */
    public function getTarget(): ?string
    {
        return $this->target;
    }

    /**
     * Guarda el destino recortado al largo de la columna: una dirección larga o
     * un valor inesperado no deben reventar el INSERT y con él el envío.
     *
     * @param string|null $target Destino del efecto.
     */
    public function setTarget(?string $target): self
    {
        $this->target = $target === null ? null : mb_substr(trim($target), 0, 255);
        return $this;
    }

    /**
     * @return \DateTimeImmutable Instante en que se apuntó el efecto.
     */
    public function getEmittedAt(): \DateTimeImmutable
    {
        return $this->emittedAt;
    }

    /**
     * @param \DateTimeImmutable $emittedAt Instante de emisión.
     */
    public function setEmittedAt(\DateTimeImmutable $emittedAt): self
    {
        $this->emittedAt = $emittedAt;
        return $this;
    }
}
