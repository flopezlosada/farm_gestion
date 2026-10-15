<?php

namespace App\Service\Partner;

use App\Entity\Partner;
use App\Entity\PartnerPause;
use App\EventSubscriber\PartnerLifecycleSubscriber;
use App\Repository\PartnerPauseRepository;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Pausa y reactiva socixs con fechas: la única vía para hacerlo, desde la ficha,
 * la acción masiva o la tarea diaria.
 *
 * Tres efectos, siempre juntos y en este orden:
 *  1. el periodo queda en {@see PartnerPause}, que es lo que consultan el reparto
 *     y las cuotas;
 *  2. las semanas YA congeladas desde hoy se rehacen para ese socix
 *     ({@see WeeklyBasketGenerator::reconcilePartnerFrom}): al pausar desaparecen
 *     sus entregas dentro del periodo y al reactivar vuelven. Las semanas futuras
 *     aún sin congelar no necesitan nada: se dibujan ya con la pausa;
 *  3. `partner.status` se pone como toca HOY ({@see syncStatus}). Si la pausa
 *     empieza más adelante, la ficha sigue activa hasta ese día y la tarea diaria
 *     hace el cambio cuando llega.
 *
 * Las reglas que rechaza lanzan \DomainException con un mensaje para la persona
 * que lo intenta.
 */
class PartnerPauser
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerPauseRepository $pauses,
        private readonly PartnerPauseCalendar $calendar,
        private readonly WeeklyBasketGenerator $generator,
        #[Target('partner_lifecycle')] private readonly WorkflowInterface $workflow,
    ) {
    }

    /**
     * Pausa a un socix desde un día, hasta otro opcional.
     *
     * @param Partner                 $partner Socix a pausar.
     * @param \DateTimeInterface      $from    Primer día sin cesta (puede ser pasado o futuro).
     * @param \DateTimeInterface|null $until   Primer día con cesta otra vez; null si no se sabe.
     * @param string|null             $actor   Quién la registra (convenio de PartnerEvent::actor).
     * @param \DateTimeInterface|null $today   Hoy; inyectable para tests.
     * @throws \DomainException Si está de baja, si la vuelta ya pasó o si se solapa con otra pausa.
     */
    public function pause(Partner $partner, \DateTimeInterface $from, ?\DateTimeInterface $until = null, ?string $actor = null, ?\DateTimeInterface $today = null): PartnerPause
    {
        $today = PartnerPause::day($today ?? new \DateTimeImmutable());
        if ($partner->getStatus() === Partner::STATUS_BAJA) {
            throw new \DomainException('Está de baja: no se puede pausar.');
        }
        if ($until !== null && PartnerPause::day($until) <= $today) {
            throw new \DomainException('La fecha de vuelta tiene que ser posterior a hoy.');
        }
        try {
            $pause = new PartnerPause($partner, $from, $until, $actor);
        } catch (\InvalidArgumentException $e) {
            throw new \DomainException($e->getMessage(), 0, $e);
        }
        foreach ($this->pauses->findForPartner($partner) as $other) {
            if ($other->overlaps($pause->getStartsOn(), $pause->getResumesOn())) {
                throw new \DomainException(sprintf('Ya tiene una pausa que se cruza con esas fechas (%s).', self::describe($other)));
            }
        }

        $this->em->persist($pause);
        $this->apply($partner, max($today, $pause->getStartsOn()), $today, $pause->getResumesOn());

        return $pause;
    }

    /**
     * Reactiva a un socix desde un día: recorta la pausa en curso o la próxima
     * programada para que termine ese día, o la anula si aún no había empezado
     * para entonces.
     *
     * @param Partner                 $partner Socix a reactivar.
     * @param \DateTimeInterface      $on      Primer día con cesta otra vez.
     * @param \DateTimeInterface|null $today   Hoy; inyectable para tests.
     * @return bool True si la pausa se anuló entera (no había empezado para ese día).
     * @throws \DomainException Si no tiene pausa que acabar, si el día ya pasó o si
     *                          alargarla la cruzaría con la siguiente.
     */
    public function resume(Partner $partner, \DateTimeInterface $on, ?\DateTimeInterface $today = null): bool
    {
        $today = PartnerPause::day($today ?? new \DateTimeImmutable());
        $on = PartnerPause::day($on);
        if ($on < $today) {
            throw new \DomainException('La fecha de vuelta no puede ser anterior a hoy.');
        }
        $upcoming = $this->pauses->findNotEndedBefore($partner, $today);
        $pause = $upcoming[0] ?? null;
        if ($pause === null) {
            // Pausadx sin periodo (una ficha de antes de que las pausas tuvieran
            // fechas): no hay nada que recortar, sólo poner la ficha al día.
            if ($partner->getStatus() !== Partner::STATUS_PAUSADO) {
                throw new \DomainException('No tiene ninguna pausa en curso ni programada.');
            }
            $this->apply($partner, $today, $today, null);

            return false;
        }
        $next = $upcoming[1] ?? null;
        if ($next !== null && $on > $next->getStartsOn()) {
            throw new \DomainException(sprintf('Esa vuelta se cruzaría con la siguiente pausa (%s).', self::describe($next)));
        }

        // Lo que hay que rehacer es el tramo que cambia, entre la vuelta vieja y la
        // nueva (en los dos sentidos: recortar devuelve entregas, alargar las quita),
        // o la pausa entera si se anula; y nunca antes de hoy (lo pasado no se toca).
        $cancelled = $on <= $pause->getStartsOn();
        $oldResume = $pause->getResumesOn();
        if ($cancelled) {
            [$from, $until] = [$pause->getStartsOn(), $oldResume];
            $this->em->remove($pause);
        } else {
            [$from, $until] = $oldResume === null
                ? [$on, null]
                : [min($on, $oldResume), max($on, $oldResume)];
            $pause->setResumesOn($on);
        }
        $this->apply($partner, max($today, $from), $today, $until);

        return $cancelled;
    }

    /**
     * Pone `partner.status` como toca ese día según sus pausas, registrando el
     * evento con la fecha real del cambio. Lo llaman este servicio y la tarea diaria.
     * No toca a quien está de baja. No hace flush.
     *
     * @param Partner            $partner Socix.
     * @param \DateTimeInterface $today   Día que manda.
     * @return bool Si el estado cambió.
     */
    public function syncStatus(Partner $partner, \DateTimeInterface $today): bool
    {
        $today = PartnerPause::day($today);
        $covering = null;
        $lastEnded = null;
        // En orden de inicio y sin solapes: la última que acabó es la más reciente.
        foreach ($this->pauses->findForPartner($partner) as $pause) {
            if ($pause->covers($today)) {
                $covering = $pause;
            } elseif ($pause->endedBefore($today)) {
                $lastEnded = $pause;
            }
        }

        $transition = match (true) {
            $covering !== null && $partner->getStatus() === Partner::STATUS_ACTIVO => 'pause',
            $covering === null && $partner->getStatus() === Partner::STATUS_PAUSADO => 'resume',
            default => null,
        };
        if ($transition === null || !$this->workflow->can($partner, $transition)) {
            return false;
        }

        // Fecha del cambio: el inicio de la pausa, o la vuelta de la última que acabó
        // (si se anuló una que ni había empezado, hoy).
        $occurredAt = $transition === 'pause'
            ? $covering->getStartsOn()
            : ($lastEnded?->getResumesOn() ?? $today);
        $this->workflow->apply($partner, $transition, [PartnerLifecycleSubscriber::CONTEXT_OCCURRED_AT => $occurredAt]);

        return true;
    }

    /**
     * Cierra las pausas de quien causa baja: la en curso termina ese día y las
     * programadas se borran. Si no, al volver al alta seguiría "en pausa" para el
     * reparto y las cuotas, y la tarea diaria le volvería a pausar. Lo llama el
     * subscriber del ciclo de vida al entrar en BAJA; no hace flush.
     *
     * @param Partner            $partner Socix que causa baja.
     * @param \DateTimeInterface $day     Día de la baja.
     */
    public function closeForLeave(Partner $partner, \DateTimeInterface $day): void
    {
        $day = PartnerPause::day($day);
        foreach ($this->pauses->findNotEndedBefore($partner, $day) as $pause) {
            if ($pause->getStartsOn() >= $day) {
                $this->em->remove($pause);
            } else {
                $pause->setResumesOn($day);
            }
        }
        $this->calendar->reset();
    }

    /**
     * Graba, olvida lo que el calendario tenía en memoria, rehace las semanas
     * congeladas de [$from, $until) y deja el estado como toca hoy.
     */
    private function apply(Partner $partner, \DateTimeImmutable $from, \DateTimeImmutable $today, ?\DateTimeImmutable $until): void
    {
        $this->em->flush();
        $this->calendar->reset();
        $this->generator->reconcilePartnerFrom($partner, $from, $until);
        $this->syncStatus($partner, $today);
        $this->em->flush();
    }

    /** "del 01/11/2026 al 15/11/2026" o "desde el 01/11/2026", para los mensajes. */
    public static function describe(PartnerPause $pause): string
    {
        $resumes = $pause->getResumesOn();

        return $resumes === null
            ? sprintf('desde el %s', $pause->getStartsOn()->format('d/m/Y'))
            : sprintf('del %s, vuelve el %s', $pause->getStartsOn()->format('d/m/Y'), $resumes->format('d/m/Y'));
    }
}
