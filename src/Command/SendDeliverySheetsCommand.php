<?php

namespace App\Command;

use App\Entity\Basket;
use App\Entity\Node;
use App\Service\AppSettings;
use App\Service\Delivery\DeliverySheetPdf;
use App\Service\Delivery\DeliverySheetSchedule;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Manda el listado del reparto en PDF en cuanto se cierra el plazo de cambios de
 * cada nodo. Sustituye al envío a mano que hoy hace administración cada semana.
 *
 * CADA NODO A SU HORA, y de ahí la cadencia diaria: Madrid cierra el martes por
 * la noche y su listado sale el miércoles a primera hora; la Sierra cierra el
 * jueves y sale el viernes. Qué repartos tocan lo decide
 * {@see DeliverySheetSchedule}; aquí sólo se generan los PDF y se mandan.
 *
 * DIRIGIDA POR ESTADO, no por el instante del disparo: un disparo a deshora da el
 * mismo resultado, dos disparos no duplican nada (el apunte de idempotencia lo
 * impide) y una pasada perdida la recupera la siguiente mientras el reparto no
 * haya ocurrido.
 *
 * --date=YYYY-MM-DD fuerza una fecha física concreta ignorando el plazo, para
 * pruebas y reenvíos; --dry-run enseña qué mandaría sin mandar nada.
 */
#[AsCommand(name: 'app:send-delivery-sheets', description: 'Envía el listado de reparto en PDF al cerrarse el plazo de cada nodo.')]
class SendDeliverySheetsCommand extends AbstractCronCommand
{
    /**
     * Clase de efecto del guardián de idempotencia. La referencia es el NODO y la
     * fecha de negocio su fecha física de reparto: dos nodos que reparten el mismo
     * día son dos envíos distintos, y el mismo nodo en dos semanas también.
     */
    private const EFFECT_KIND = 'delivery_sheet';

    public function __construct(
        private readonly DeliverySheetSchedule $schedule,
        private readonly DeliverySheetPdf $sheetPdf,
        private readonly AppSettings $settings,
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Email(s) destinatarios, separados por comas. Si se omite, se usa el ajuste de /gestion/settings (Destinatario(s) del listado de reparto)')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Fecha física de reparto objetivo (YYYY-MM-DD); ignora el plazo de cierre')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual); no afecta a los toggles de email')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Reenvía aunque el listado ya conste enviado (para correos que no llegaron)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No envía, sólo lista qué listados saldrían');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');

        $to = $input->getOption('to') ?: $this->settings->getString(AppSettings::EMAIL_DELIVERY_SHEET_TO);
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) $to))));

        // Sin destinatario la tarea no falla: sale sana y sin trabajo. Un fallo
        // aquí dejaría el registro en rojo cada mañana y el reloj externo avisando
        // de una avería que no lo es — basta con que nadie haya rellenado el
        // ajuste todavía.
        if (!$dryRun && $recipients === []) {
            $io->warning('Sin destinatario configurado: rellena «Destinatario(s) del listado de reparto» en /gestion/settings o pasa --to. No se envía nada.');

            return $this->nothingToDo('Sin destinatario configurado');
        }

        $target = $this->optionalDate($input, $io);
        if ($target === false) {
            return Command::FAILURE;
        }

        $pending = $this->schedule->pending($target);

        if ($pending === []) {
            $io->note($target !== null
                ? sprintf('Ningún nodo reparte el %s.', $target->format('Y-m-d'))
                : 'Ningún nodo ha cerrado su plazo con el reparto todavía por delante.');

            return $this->nothingToDo($target !== null
                ? sprintf('Ningún nodo reparte el %s', $target->format('Y-m-d'))
                : 'Ningún nodo con el plazo recién cerrado');
        }

        $io->table(
            ['Nodo', 'Reparto', 'Cierre del plazo'],
            array_map(static fn (array $row): array => [
                $row['node']->getName(),
                $row['physical_date']->format('Y-m-d'),
                $row['deadline']->format('Y-m-d H:i'),
            ], $pending),
        );

        if ($dryRun) {
            $io->success(sprintf('Dry-run: %d listado(s). No se ha enviado nada.', count($pending)));

            return Command::SUCCESS;
        }

        $sent = 0;
        $already = 0;
        $empty = 0;

        foreach ($pending as $row) {
            $pdf = $this->sheetPdf->renderWeekly($row['basket'], [$row['node']]);

            // null = ese nodo no aporta hoja (reparto cancelado por una excepción
            // registrada después de materializar, típicamente). No hay listado que
            // mandar y tampoco es un fallo.
            if ($pdf === null) {
                ++$empty;
                continue;
            }

            $emitted = $this->emitOnce(
                self::EFFECT_KIND,
                fn () => $this->mailer->send($this->message($row, $pdf, $recipients)),
                $input,
                reference: sprintf('node-%d', $row['node']->getId()),
                on: $row['physical_date'],
                target: implode(', ', $recipients),
            );

            if ($emitted) {
                ++$sent;
            } else {
                ++$already;
            }
        }

        if ($sent === 0) {
            $io->note('Nada nuevo que enviar.');

            return $this->nothingToDo(sprintf(
                '%d listado(s) ya enviados, %d sin reparto',
                $already,
                $empty,
            ));
        }

        $io->success(sprintf('Enviados %d listado(s) a %s.', $sent, implode(', ', $recipients)));

        return $this->didWork(sprintf(
            '%d listado(s) enviados a %s (%d ya enviados, %d sin reparto)',
            $sent,
            implode(', ', $recipients),
            $already,
            $empty,
        ));
    }

    /**
     * Correo con el listado adjunto. El cuerpo es deliberadamente escueto: el
     * documento es el PDF, y quien lo recibe ya sabe qué hacer con él.
     *
     * @param array{node: Node, basket: Basket, physical_date: \DateTimeImmutable, deadline: \DateTimeImmutable} $row
     * @param string   $pdf        Bytes del listado.
     * @param string[] $recipients Direcciones destinatarias.
     */
    private function message(array $row, string $pdf, array $recipients): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->to(...$recipients)
            ->subject(sprintf(
                'CSA Vega · Listado de reparto de %s · %s',
                $row['node']->getName(),
                $row['physical_date']->format('d/m/Y'),
            ))
            ->htmlTemplate('email/delivery_sheet.html.twig')
            ->textTemplate('email/delivery_sheet.txt.twig')
            ->context([
                'node_name' => $row['node']->getName(),
                'pickup_date' => $row['physical_date'],
                'deadline' => $row['deadline'],
            ])
            ->addPart(new DataPart($pdf, $this->sheetPdf->weeklyFilename($row['basket']), 'application/pdf'));
    }


}
