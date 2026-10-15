<?php

namespace App\Service\Partner;

use App\Entity\Partner;
use App\Entity\PartnerPause;
use App\Repository\PartnerPauseRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Responde "¿este socix está en pausa ese día?" para el reparto.
 *
 * El generador lo pregunta por cada socix y cada semana que dibuja (un mes de
 * calendario son cientos de preguntas), así que carga TODAS las pausas en la
 * primera pregunta y contesta en memoria: una consulta por petición. Cabe porque
 * la tabla es diminuta (unas pocas pausas al año por socix que se va de vacaciones).
 *
 * Lo cargado se olvida al acabar cada petición ({@see ResetInterface}) y cuando
 * {@see PartnerPauser} cambia una pausa, que llama a {@see reset()} para que el
 * reparto que rehace justo después vea la pausa nueva.
 */
class PartnerPauseCalendar implements ResetInterface
{
    /** @var array<int, list<PartnerPause>>|null Id de socix => sus pausas; null sin cargar. */
    private ?array $byPartner = null;

    public function __construct(private readonly PartnerPauseRepository $pauses)
    {
    }

    /**
     * ¿Está en pausa ese día?
     *
     * @param Partner            $partner Socix.
     * @param \DateTimeInterface $day     Día de la entrega (se ignora la hora).
     */
    public function isPausedOn(Partner $partner, \DateTimeInterface $day): bool
    {
        foreach ($this->pausesOf($partner) as $pause) {
            if ($pause->covers($day)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Tiene alguna pausa, pasada o futura? Sirve para no calcular la fecha de la
     * entrega (que cuesta una consulta) de quien nunca se ha pausado.
     */
    public function hasPauses(Partner $partner): bool
    {
        return $this->pausesOf($partner) !== [];
    }

    /** Olvida lo cargado: la próxima pregunta vuelve a leer la base. */
    public function reset(): void
    {
        $this->byPartner = null;
    }

    /** @return list<PartnerPause> */
    private function pausesOf(Partner $partner): array
    {
        $id = $partner->getId();
        if ($id === null) {
            return [];
        }
        if ($this->byPartner === null) {
            $this->byPartner = [];
            foreach ($this->pauses->findAll() as $pause) {
                $this->byPartner[$pause->getPartner()->getId()][] = $pause;
            }
        }

        return $this->byPartner[$id] ?? [];
    }
}
