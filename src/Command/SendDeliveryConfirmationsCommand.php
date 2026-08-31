<?php

namespace App\Command;

use App\Service\Delivery\DeliveryConfirmationMailer;
use App\Service\Delivery\DeliverySheetSchedule;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Confirma a cada socix qué le queda registrado para el reparto cuyo plazo acaba
 * de cerrar: o recoge tal día en tal sitio, o esta semana no recoge.
 *
 * Comparte disparador con el envío del listado ({@see SendDeliverySheetsCommand}):
 * los dos preguntan a {@see DeliverySheetSchedule} qué nodos han cerrado, así que
 * cada nodo recibe lo suyo en su día —Madrid el miércoles, la Sierra el viernes— y
 * no hay dos definiciones de "cerrado" que puedan separarse con el tiempo. Son
 * tareas distintas porque tienen destinatarios y interruptores distintos: apagar
 * el listado interno no debe callar el aviso a lxs socixs, ni al revés.
 *
 * A quién se escribe y por qué también a quien NO recoge lo explica
 * {@see DeliveryConfirmationMailer}.
 */
#[AsCommand(name: 'app:send-delivery-confirmations', description: 'Confirma a cada socix su cesta en cuanto cierra el plazo de su nodo.')]
class SendDeliveryConfirmationsCommand extends AbstractCronCommand
{
    public function __construct(
        private readonly DeliverySheetSchedule $schedule,
        private readonly DeliveryConfirmationMailer $confirmationMailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Fecha física de reparto objetivo (YYYY-MM-DD); ignora el plazo de cierre')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual); no afecta a los toggles de email')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Reenvía aunque la confirmación ya conste enviada (para correos que no llegaron)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No envía, sólo lista a quién escribiría y qué le diría');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = $this->optionalDate($input, $io);
        if ($target === false) {
            return Command::FAILURE;
        }

        $pending = $this->schedule->pending($target);

        if ($pending === []) {
            $io->note('Ningún nodo ha cerrado su plazo con el reparto todavía por delante.');

            return $this->nothingToDo('Ningún nodo con el plazo recién cerrado');
        }

        $rows = [];
        $audiences = [];
        foreach ($pending as $delivery) {
            $audience = $this->confirmationMailer->audienceFor(
                $delivery['node'],
                $delivery['basket'],
                $delivery['physical_date'],
            );

            $audiences[] = $audience;

            foreach ($audience as $entry) {
                $rows[] = [
                    trim($entry['partner']->getName() . ' ' . $entry['partner']->getSurname()),
                    $entry['partner']->getEmail() ?: '(sin email)',
                    $entry['node_name'] ?? '(sin nodo)',
                    $entry['pickup_date']->format('Y-m-d'),
                    $entry['picks'] ? 'recoge' : 'no recoge',
                ];
            }
        }

        if ($rows === []) {
            $io->note('Los repartos que han cerrado no tienen a nadie a quien confirmar.');

            return $this->nothingToDo('Sin destinatarios en los repartos cerrados');
        }

        $io->table(['Socix', 'Email', 'Nodo', 'Reparto', 'Qué se le dice'], $rows);

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Dry-run: %d confirmación(es). No se ha enviado nada.', count($rows)));

            return Command::SUCCESS;
        }

        $resend = (bool) $input->getOption('resend');

        $sent = 0;
        $skipped = 0;
        $already = 0;
        foreach ($audiences as $audience) {
            $result = $this->confirmationMailer->send($audience, $resend);
            $sent += $result['sent'];
            $skipped += $result['skipped'];
            $already += $result['already'];
        }

        $io->success(sprintf(
            'Enviadas %d confirmación(es). %d socixs sin email. %d ya estaban avisados.',
            $sent,
            $skipped,
            $already,
        ));

        if ($sent === 0) {
            return $this->nothingToDo(sprintf(
                '%d destinatarios, todos avisados ya (%d sin email)',
                $already,
                $skipped,
            ));
        }

        return $this->didWork(sprintf(
            '%d confirmaciones enviadas (%d sin email, %d ya avisados)',
            $sent,
            $skipped,
            $already,
        ));
    }
}
