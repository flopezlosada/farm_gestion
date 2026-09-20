<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Horario habitual de un día de la semana: los tramos en los que se espera que
 * se trabaje ("09:00-14:00, 15:30-18:00"). Es la plantilla que
 * {@see \App\Service\Staff\ScheduleAutofill} usa para rellenar los fichajes que
 * falten un día concreto (el botón "rellenar con mi horario" del panel del
 * trabajador).
 *
 * GLOBAL CON EXCEPCIÓN POR TRABAJADOR: {@see WorkSchedule::$worker} en null es
 * la plantilla de toda la asociación; con valor es la excepción de ESE
 * trabajador para ese día de la semana, que manda sobre la global (p. ej.
 * alguien a media jornada). No hay UNIQUE de BD en (worker, dayOfWeek): con
 * worker nullable, MySQL no evita dos filas globales del mismo día (NULL no
 * colisiona consigo mismo). La integridad la da el flujo de guardado
 * ({@see \App\Controller\WorkScheduleController}), que siempre hace
 * find-or-create sobre las 7 filas de la semana y nunca inserta libre.
 *
 * @ORM\Table(name="work_schedule")
 * @ORM\Entity(repositoryClass="App\Repository\WorkScheduleRepository")
 */
class WorkSchedule
{
    /** Lunes, en ISO-8601 (DateTimeImmutable::format('N')). */
    public const MONDAY = 1;
    /** Domingo, en ISO-8601. */
    public const SUNDAY = 7;

    /**
     * @var int|null
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Trabajador al que aplica esta fila, o null si es la plantilla global.
     *
     * @var Worker|null
     * @ORM\ManyToOne(targetEntity="Worker")
     * @ORM\JoinColumn(name="worker_id", referencedColumnName="id", nullable=true, onDelete="CASCADE")
     */
    private $worker;

    /**
     * Día de la semana en ISO-8601: 1 (lunes) a 7 (domingo).
     *
     * @var int
     * @ORM\Column(name="day_of_week", type="integer")
     */
    #[Assert\Range(min: self::MONDAY, max: self::SUNDAY)]
    private $dayOfWeek;

    /**
     * Tramos horarios del día, lista de pares [inicio, fin] en formato "HH:MM".
     * Mismo formato que {@see VolunteerOffer::$repeatTimes}. Vacío/null = ese día
     * no se trabaja (fin de semana, o un día con jornada partida al que le falta
     * el tramo de tarde).
     *
     * @var list<array{0: string, 1: string}>|null
     * @ORM\Column(name="times", type="json", nullable=true)
     */
    private $times;

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
     * @param Worker|null $worker
     * @return self
     */
    public function setWorker(?Worker $worker): self
    {
        $this->worker = $worker;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getDayOfWeek(): ?int
    {
        return $this->dayOfWeek;
    }

    /**
     * @param int $dayOfWeek
     * @return self
     */
    public function setDayOfWeek(int $dayOfWeek): self
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    /**
     * @return list<array{0: string, 1: string}> Tramos horarios; lista vacía si no hay ninguno.
     */
    public function getTimes(): array
    {
        return $this->times ?? [];
    }

    /**
     * @param list<array{0: string, 1: string}>|null $times
     * @return self
     */
    public function setTimes(?array $times): self
    {
        $this->times = ([] === $times) ? null : $times;

        return $this;
    }
}
