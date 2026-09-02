<?php

namespace App\Command;

use App\Entity\VolunteerOffer;
use App\Repository\VolunteerOfferRepository;
use App\Service\Volunteering\ShiftGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Abre los turnos que le tocan a cada tarea repetitiva, y retira los que su
 * receta ya no dicta.
 *
 * POR QUÉ HACE FALTA. Los turnos no se materializan hasta la fecha final de la
 * serie sino hasta unos meses vista ({@see ShiftGenerator::HORIZON_DAYS}): si no
 * fuera así, "sacar al perro, mañana y tarde, hasta fin de año" nacería con
 * setecientas treinta filas de golpe y el primer dedazo en la fecha final
 * llenaría la tabla. El precio de ese horizonte es que alguien tiene que irlo
 * empujando, y ese alguien es esta tarea. Sin ella, una tarea repetitiva que
 * nadie vuelva a editar se queda sin turnos a los cuatro meses, EN SILENCIO: la
 * ficha aparece vacía y no hay a qué apuntarse.
 *
 * NO MANDA NADA A NADIE, y de ahí que no lleve canales ni confirmación. Lo único
 * que hace es mantener el calendario al día; los avisos son de otras tareas.
 *
 * NO PASA POR {@see \App\Service\Cron\EffectLedger} a propósito: no entrega
 * nada, así que no hay nada que no se pueda repetir. La idempotencia la da el
 * propio generador —crea lo que falta y no toca lo que está— y el UNIQUE
 * (offer_id, starts_at) de la base la garantiza de verdad.
 */
#[AsCommand(
    name: 'app:sync-volunteer-shifts',
    description: 'Abre los turnos de las tareas de voluntariado que se repiten.',
)]
class SyncVolunteerShiftsCommand extends AbstractCronCommand
{
    public function __construct(
        private readonly VolunteerOfferRepository $offers,
        private readonly ShiftGenerator $generator,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué turnos se abrirían, sin tocar nada');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTime();

        $repeating = $this->offers->findRepeating();

        // La previsualización se atiende ANTES del atajo de "no hay nada": el
        // atajo reporta al andamiaje, que en dry-run no imprime nada, y un
        // comando que se ejecuta y no dice ni una palabra no se distingue de uno
        // que ha fallado en silencio.
        if ((bool) $input->getOption('dry-run')) {
            return $this->preview($io, $repeating, $now);
        }

        if ([] === $repeating) {
            return $this->nothingToDo('No hay ninguna tarea de voluntariado que se repita.');
        }

        $opened = 0;
        $removed = 0;
        $kept = 0;

        foreach ($repeating as $offer) {
            $sync = $this->generator->sync($offer, $now);

            $opened += \count($sync['created']);
            $removed += $sync['removed'];
            $kept += \count($sync['kept']);
        }

        // Un solo flush al final y no uno por tarea: son unas pocas decenas de
        // tareas y así una que falle no deja el calendario a medias.
        $this->em->flush();

        if (0 === $opened && 0 === $removed) {
            return $this->nothingToDo('Los turnos ya estaban al día.');
        }

        $said = sprintf('%d turno(s) abiertos', $opened);

        if ($removed > 0) {
            $said .= sprintf(', %d retirados', $removed);
        }

        // Los que la receta ya no dicta pero tienen gente apuntada se cuentan
        // aparte: no son un error, son una decisión pendiente de alguien.
        if ($kept > 0) {
            $said .= sprintf(', %d con gente apuntada que ya no encajan con su repetición', $kept);
        }

        return $this->didWork($said.'.');
    }

    /**
     * Enseña qué se abriría, sin tocar nada.
     *
     * @param SymfonyStyle         $io        la salida
     * @param list<VolunteerOffer> $repeating las tareas con receta
     * @param \DateTimeInterface   $now       momento de referencia
     *
     * @return int código de salida del comando
     */
    private function preview(SymfonyStyle $io, array $repeating, \DateTimeInterface $now): int
    {
        $rows = [];

        foreach ($repeating as $offer) {
            // Los momentos que la receta dicta, y cuántos de ésos son turnos que
            // ya existen: la diferencia es lo que se abriría.
            $moments = $this->generator->moments($offer, $now);

            $existing = [];
            foreach ($offer->getShifts() as $shift) {
                $startsAt = $shift->getStartsAt();
                if (null !== $startsAt) {
                    $existing[$startsAt->format('Y-m-d H:i')] = true;
                }
            }

            $missing = 0;
            foreach ($moments as [$start]) {
                if (!isset($existing[$start->format('Y-m-d H:i')])) {
                    ++$missing;
                }
            }

            if (0 === $missing) {
                continue;
            }

            $rows[] = [
                $offer->getId(),
                $offer->getTitle(),
                $offer->getRepeatType(),
                $offer->getRepeatUntil()?->format('d/m/Y') ?? 'sin fecha final',
                $missing,
            ];
        }

        if ([] === $repeating) {
            $io->success('No hay ninguna tarea de voluntariado que se repita: nada que abrir.');

            return self::SUCCESS;
        }

        if ([] === $rows) {
            $io->success(sprintf(
                '%d tarea(s) con repetición, y todas con sus turnos al día.',
                \count($repeating)
            ));

            return self::SUCCESS;
        }

        $io->table(['#', 'Tarea', 'Repetición', 'Hasta', 'Turnos a abrir'], $rows);
        $io->note('Previsualización: no se ha creado ni retirado nada.');

        return self::SUCCESS;
    }
}
