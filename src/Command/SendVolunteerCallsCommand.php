<?php

namespace App\Command;

use App\Repository\VolunteerShiftRepository;
use App\Service\Volunteering\VolunteerAudienceResolver;
use App\Service\Volunteering\VolunteerCallEscalator;
use App\Service\Volunteering\VolunteerCallNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Abre por pasos los avisos de las tareas de voluntariado que siguen sin
 * cubrir: primero a quien ha marcado esa categoría en su ficha y, pasadas unas
 * horas, a quien no ha marcado ninguna — esto último sólo en tareas aptas para
 * cualquiera.
 *
 * Nunca llega solo a todo el mundo. Ese alcance se lanza a mano desde gestión,
 * porque es una decisión de gastar el canal y un automatismo no sabe distinguir
 * "falta gente para la plantación" de "si no vienen se pierde la cosecha".
 *
 * De cadencia fina (cada hora) a diferencia del resto de tareas, que tienen su
 * hora del día: con cadencia diaria, el segundo paso de una tarea que es pasado
 * mañana llegaría cuando ya no sirve.
 *
 * No pasa por {@see \App\Service\Cron\EffectLedger} y no es un olvido: la
 * repetición ya la impide la unicidad (shift, scope) de `volunteer_call`, que es
 * más fuerte que un apunte por día — vale también para el envío a mano desde
 * gestión, que el ledger no vería.
 */
#[AsCommand(
    name: 'app:send-volunteer-calls',
    description: 'Abre por pasos los avisos de las tareas de voluntariado sin cubrir.',
)]
class SendVolunteerCallsCommand extends AbstractCronCommand
{
    public function __construct(
        private readonly VolunteerCallNotifier $notifier,
        private readonly VolunteerShiftRepository $shifts,
        private readonly VolunteerCallEscalator $escalator,
        private readonly VolunteerAudienceResolver $audience,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            // Desde que este aviso también sale por correo, entra en el contrato
            // de todo lo que manda email: sin vía de repetición, un correo que no
            // llegó sólo se rescataría borrando su apunte a mano en la base.
            //
            // OJO con usarlo: el mismo aviso lleva push, y repetir un push no es
            // gratis — el permiso del navegador se gasta una vez y quien lo apaga
            // no vuelve. Por eso el gobierno de la repetición sigue siendo el
            // UNIQUE (shift, scope) del dominio y esto es la salida de
            // emergencia, no el camino normal.
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Repite el envío aunque ya conste emitido (para un correo que no llegó). Cuidado: repite también el push')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista los avisos que saldrían, sin enviar ni registrar nada');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        if ((bool) $input->getOption('dry-run')) {
            return $this->preview($io, $now);
        }

        $sent = $this->notifier->dispatchDue($now);

        return 0 === $sent
            ? $this->nothingToDo('Ningún turno de voluntariado necesita aviso ahora mismo.')
            : $this->didWork(sprintf('%d aviso(s) de voluntariado enviados.', $sent));
    }

    /**
     * Enseña qué saldría sin mandar nada. Repite la orquestación del notificador
     * a propósito: hacerlo con un flag "no envíes de verdad" metido dentro
     * significaría que el camino que se previsualiza no es exactamente el que
     * luego se ejecuta, y una previsualización que miente no sirve de nada.
     *
     * @param SymfonyStyle       $io  la salida
     * @param \DateTimeImmutable $now momento de referencia
     *
     * @return int código de salida del comando
     */
    private function preview(SymfonyStyle $io, \DateTimeImmutable $now): int
    {
        $rows = [];
        foreach ($this->shifts->findUpcoming($now) as $shift) {
            $scope = $this->escalator->nextScope($shift, $now);
            if (null === $scope) {
                continue;
            }

            $rows[] = [
                $shift->getId(),
                $shift->getOffer()?->getTitle() ?? '—',
                $shift->getStartsAt()?->format('d/m/Y H:i') ?? '—',
                $shift->getRemainingSlots() ?? 'sin tope',
                $scope,
                $this->audience->count($shift, $scope),
            ];
        }

        if ([] === $rows) {
            $io->success('Ningún turno de voluntariado necesita aviso ahora mismo.');

            return self::SUCCESS;
        }

        $io->table(['#', 'Tarea', 'Cuándo', 'Faltan', 'Alcance', 'Destinatarixs'], $rows);
        $io->note('Previsualización: no se ha enviado ni registrado nada.');

        return self::SUCCESS;
    }
}
