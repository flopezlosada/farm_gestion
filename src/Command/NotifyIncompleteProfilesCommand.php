<?php

namespace App\Command;

use App\Service\Notification\IncompleteProfileNotifier;
use App\Service\Partner\PartnerProfileCompleteness;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Persigue las fichas de socix a las que les faltan datos.
 *
 * SEMANAL Y SÓLO POR LA BANDEJA. Un dato que falta no tiene día ni hora y lleva
 * ahí meses: mandarlo al móvil o al correo sería el aviso que enseña a ignorar los
 * avisos, y el que hace que el siguiente —"hoy recoges tu cesta"— llegue a alguien
 * que ya no los mira. En la bandeja espera sin molestar.
 *
 * NO PASA POR {@see \App\Service\Cron\EffectLedger}, al revés que los otros avisos
 * del planificador, y es la parte que hay que entender antes de "arreglarlo". La
 * regla no es "uno por semana" sino "otro cuando el anterior se haya leído y el
 * problema siga": si el aviso sigue sin abrir, ya está diciendo lo que tiene que
 * decir y otro igual encima sólo infla la campanita. El ledger sabe si aquella
 * semana se avisó, pero no si la persona lo leyó, que es justo la condición; la
 * resuelve {@see \App\Repository\NotificationRepository::hasUnreadOfKind()}.
 *
 * Como consecuencia el comando es IDEMPOTENTE POR SÍ MISMO: correrlo dos veces
 * seguidas no duplica nada, porque el primer aviso queda sin leer. Por eso tampoco
 * declara --resend: no hay nada que reenviar, y para volver a avisar a alguien
 * basta con que abra lo que tiene.
 */
#[AsCommand(
    name: 'app:notify-incomplete-profiles',
    description: 'Avisa a cada socix de los datos que le faltan, y a quien coordina socixs de cuántas fichas están a medias.',
)]
class NotifyIncompleteProfilesCommand extends AbstractCronCommand
{
    public function __construct(
        private readonly IncompleteProfileNotifier $notifier,
        private readonly PartnerProfileCompleteness $completeness,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista las fichas a medias y lo que le falta a cada una, sin avisar a nadie');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('dry-run')) {
            return $this->preview($io);
        }

        $result = $this->notifier->notify();

        $io->section(sprintf('%d ficha(s) activa(s) con datos sin rellenar', $result['incomplete']));

        if (0 === $result['incomplete']) {
            return $this->nothingToDo('Todas las fichas activas están completas.');
        }

        $io->success(sprintf(
            '%d aviso(s) a socixs y %d a quien coordina.',
            $result['partners'],
            $result['coordinators'],
        ));

        // Cero avisos nuevos con fichas a medias es el caso NORMAL de la segunda
        // semana: todo el mundo tiene el suyo sin leer todavía. No es una avería y
        // el registro de /gestion/settings no debe contarlo como trabajo.
        if (0 === $result['partners'] && 0 === $result['coordinators']) {
            return $this->nothingToDo(sprintf(
                '%d fichas a medias, y todo el mundo con su aviso aún sin leer.',
                $result['incomplete'],
            ));
        }

        return $this->didWork(sprintf(
            '%d fichas a medias · %d aviso(s) a socixs · %d a quien coordina',
            $result['incomplete'],
            $result['partners'],
            $result['coordinators'],
        ));
    }

    /**
     * Enseña qué fichas están a medias y qué le falta a cada una, sin avisar ni
     * apuntar nada.
     *
     * Distingue en dos columnas lo que rellena el socix de lo que rellena
     * administración, porque es la información con la que se decide qué hacer: la
     * primera se avisa y se espera, la segunda hay que perseguirla a mano.
     *
     * @param SymfonyStyle $io la salida
     *
     * @return int código de salida del comando
     */
    private function preview(SymfonyStyle $io): int
    {
        $incomplete = $this->notifier->incompleteProfiles();

        if ([] === $incomplete) {
            $io->success('Todas las fichas activas están completas.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($incomplete as $row) {
            $partner = $row['partner'];
            $mine = $this->completeness->missingSelfService($partner);

            $rows[] = [
                trim(sprintf('%s %s', $partner->getName(), $partner->getSurname())),
                $partner->getemail() ?: '(sin correo)',
                implode(', ', $mine) ?: '—',
                implode(', ', array_diff($row['missing'], $mine)) ?: '—',
            ];
        }

        $io->table(['Socix', 'Correo', 'Lo rellena elle', 'Lo rellenamos aquí'], $rows);
        $io->note('Previsualización: no se ha avisado a nadie.');

        return self::SUCCESS;
    }
}
