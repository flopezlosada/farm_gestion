<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Lo que cuesta un {@see Worker} durante un tramo de tiempo: su sueldo bruto anual, en
 * cuántas pagas lo cobra y qué porcentaje paga la asociación encima a la Seguridad
 * Social. Es lo que necesita la contabilidad para prever los sueldos de los meses que
 * faltan; el coste de cada mes NO se guarda, lo calcula {@see \App\Service\Accounting\PayrollCost}.
 *
 * Cuelga de la ficha del registro de jornada, y no de una ficha propia, para que la
 * misma persona no esté dos veces. Pero vive en su tabla porque lo ve sólo ROLE_ADMIN:
 * quien lleva los fichajes no tiene por qué ver sueldos.
 *
 * Un tramo por cada sueldo distinto. Cuando cambia (subida de convenio, cambio de
 * jornada) se cierra el tramo y se abre otro: los meses ya pasados siguen costando lo
 * que costaron. Un temporero es una ficha con un tramo que acaba. Entre dos tramos de la
 * misma persona puede haber hueco (vuelve la temporada siguiente), pero no solape: un
 * mes no puede pagarse dos veces.
 *
 * @ORM\Table(name="worker_salary", uniqueConstraints={@ORM\UniqueConstraint(name="worker_salary_start", columns={"worker_id", "valid_from"})})
 * @ORM\Entity(repositoryClass="App\Repository\WorkerSalaryRepository")
 */
class WorkerSalary
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Sin borrado en cascada a propósito: la ficha la puede borrar gestión laboral, y
     * con ella se iría en silencio el histórico de sueldos que sólo ve admin. La base
     * de datos lo impide; {@see \App\Controller\WorkerController::delete()} lo explica.
     *
     * @ORM\ManyToOne(targetEntity="Worker")
     * @ORM\JoinColumn(name="worker_id", nullable=false)
     */
    private ?Worker $worker = null;

    /**
     * Primer día con este sueldo.
     *
     * @ORM\Column(name="valid_from", type="date_immutable")
     */
    #[Assert\NotNull(message: 'Pon desde cuándo cobra este sueldo.')]
    private ?\DateTimeImmutable $validFrom = null;

    /**
     * Último día con este sueldo; vacío mientras siga.
     *
     * @ORM\Column(name="valid_to", type="date_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $validTo = null;

    /**
     * Sueldo bruto del año entero, con las pagas extra: lo que dice el contrato. Sin la
     * retribución en especie, que la nómina suma y vuelve a restar: no sale de caja.
     *
     * @ORM\Column(name="annual_gross", type="decimal", precision=10, scale=2)
     */
    #[Assert\NotNull(message: 'Pon el bruto anual.')]
    #[Assert\Positive]
    private ?string $annualGross = null;

    /**
     * @ORM\Column(name="payments", type="smallint", enumType=PaySchedule::class)
     */
    private PaySchedule $payments = PaySchedule::Fourteen;

    /**
     * Lo que paga la asociación a la Seguridad Social, en % del bruto mensual
     * prorrateado (bruto anual ÷ 12). Sale de una nómina normal de la gestoría:
     * «Total aportación empresarial» entre ese bruto. Ronda el 33 %; el exacto depende
     * del contrato (el desempleo cotiza más en los temporales) y lleva dentro lo que
     * cotice la especie.
     *
     * @ORM\Column(name="employer_ss_rate", type="decimal", precision=5, scale=2)
     */
    #[Assert\NotNull(message: 'Pon el % de Seguridad Social de la empresa.')]
    #[Assert\Range(min: 0, max: 100)]
    private ?string $employerSsRate = null;

    /**
     * @ORM\Column(name="created", type="datetime")
     * @Gedmo\Timestampable(on="create")
     */
    private ?\DateTime $created = null;

    /**
     * @ORM\Column(name="updated", type="datetime")
     * @Gedmo\Timestampable(on="update")
     */
    private ?\DateTime $updated = null;

    /**
     * Un tramo que acaba antes de empezar no cubre ningún día.
     */
    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->validFrom !== null && $this->validTo !== null && $this->validTo < $this->validFrom) {
            $context->buildViolation('Acaba antes de empezar.')->atPath('validTo')->addViolation();
        }
    }

    /**
     * ¿Comparte algún día con otro tramo? Dos tramos abiertos siempre se pisan.
     */
    public function overlaps(self $other): bool
    {
        $endsAfterOtherStarts = $this->validTo === null || $this->validTo >= $other->validFrom;
        $otherEndsAfterThisStarts = $other->validTo === null || $other->validTo >= $this->validFrom;

        return $endsAfterOtherStarts && $otherEndsAfterThisStarts;
    }

    /**
     * Lo que cobra en cada nómina ordinaria: el bruto entre el número de pagas.
     */
    public function getMonthlyGross(): float
    {
        return (float) $this->annualGross / $this->payments->value;
    }

    /**
     * La base de cotización de un mes: el bruto anual entre doce. Las pagas extra
     * cotizan prorrateadas todos los meses aunque se cobren en julio y diciembre.
     */
    public function getMonthlyContributionBase(): float
    {
        return (float) $this->annualGross / 12;
    }

    /**
     * La Seguridad Social de la empresa como fracción (31,40 % → 0,314).
     */
    public function getEmployerSsFraction(): float
    {
        return (float) $this->employerSsRate / 100;
    }

    /**
     * ¿Cobra pagas extra aparte, en julio y diciembre?
     */
    public function hasSeparateExtraPays(): bool
    {
        return $this->payments === PaySchedule::Fourteen;
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Worker|null
     */
    public function getWorker(): ?Worker
    {
        return $this->worker;
    }

    /**
     * @param Worker $worker
     */
    public function setWorker(Worker $worker): self
    {
        $this->worker = $worker;

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    /**
     * @param \DateTimeImmutable|null $validFrom
     */
    public function setValidFrom(?\DateTimeImmutable $validFrom): self
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    /**
     * @param \DateTimeImmutable|null $validTo
     */
    public function setValidTo(?\DateTimeImmutable $validTo): self
    {
        $this->validTo = $validTo;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getAnnualGross(): ?string
    {
        return $this->annualGross;
    }

    /**
     * @param string|null $annualGross
     */
    public function setAnnualGross(?string $annualGross): self
    {
        $this->annualGross = $annualGross;

        return $this;
    }

    /**
     * @return PaySchedule
     */
    public function getPayments(): PaySchedule
    {
        return $this->payments;
    }

    /**
     * @param PaySchedule $payments
     */
    public function setPayments(PaySchedule $payments): self
    {
        $this->payments = $payments;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getEmployerSsRate(): ?string
    {
        return $this->employerSsRate;
    }

    /**
     * @param string|null $employerSsRate
     */
    public function setEmployerSsRate(?string $employerSsRate): self
    {
        $this->employerSsRate = $employerSsRate;

        return $this;
    }
}
