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
 * CADA NODO A SU HORA, y de ahí la cadencia diaria: los nodos del miércoles
 * (Cascorro, Midori, El Berrueco) cierran el martes por la noche y su listado
 * sale el miércoles a primera hora; Torremocha cierra el jueves y sale el
 * viernes. Qué repartos tocan lo decide {@see DeliverySheetSchedule}; aquí sólo
 * se generan los PDF y se mandan.
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

        // Respaldo para los nodos que no tengan a nadie asignado. --to lo pisa
        // todo, incluidos los destinatarios propios de cada nodo: quien lo pasa a
        // mano quiere el listado en SU buzón, normalmente para probar.
        $to = $input->getOption('to') ?: $this->settings->getString(AppSettings::EMAIL_DELIVERY_SHEET_TO);
        $fallback = array_values(array_filter(array_map('trim', explode(',', (string) $to))));
        $forced = (bool) $input->getOption('to');

        // Pasar --to no es gratis y conviene decirlo: el listado NO llega a quien
        // lo tiene asignado en su punto, y el apunte de idempotencia queda puesto
        // igual, así que el envío de verdad de hoy ya no saldrá. Para mirar sin
        // consecuencias está --dry-run.
        if ($forced && !$dryRun) {
            $io->warning('Con --to el listado va sólo a esa dirección y NO a quien lo tenga asignado en su punto. El envío queda apuntado como hecho, así que el de esta mañana ya no saldrá.');
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
            ['Nodo', 'Reparto', 'Cierre del plazo', 'Congelado', 'Destinatarios'],
            array_map(function (array $row) use ($fallback, $forced): array {
                $to = $this->recipientsFor($row['node'], $fallback, $forced);

                return [
                    $row['node']->getName(),
                    $row['physical_date']->format('Y-m-d'),
                    $row['deadline']->format('Y-m-d H:i'),
                    $row['frozen'] ? 'sí' : 'NO, no se manda',
                    $to === [] ? '(nadie)' : implode(', ', $to),
                ];
            }, $pending),
        );

        // Sin congelar no se manda, aunque el listado se pudiera dibujar al vuelo:
        // este correo dice "el plazo se cerró, esto es definitivo", y un dibujo
        // todavía se mueve. Quien lo necesite igual lo descarga de la pantalla.
        $thawed = array_values(array_filter($pending, static fn (array $row): bool => !$row['frozen']));
        $pending = array_values(array_filter($pending, static fn (array $row): bool => $row['frozen']));

        foreach ($thawed as $row) {
            $io->warning(sprintf(
                'El reparto de %s del %s ha cerrado su plazo pero su semana NO está congelada: no se manda el listado.',
                $row['node']->getName(),
                $row['physical_date']->format('Y-m-d'),
            ));
        }

        if ($pending === []) {
            return $this->nothingToDo(sprintf('%d reparto(s) cerrados pero sin congelar', count($thawed)));
        }

        if ($dryRun) {
            $io->success(sprintf('Dry-run: %d listado(s). No se ha enviado nada.', count($pending)));

            return Command::SUCCESS;
        }

        $sent = 0;
        $already = 0;
        $empty = 0;
        $orphan = 0;

        foreach ($pending as $row) {
            $recipients = $this->recipientsFor($row['node'], $fallback, $forced);

            // Un nodo sin nadie a quien mandárselo no es un fallo de la tarea: es
            // configuración que falta. Se avisa por nodo y se sigue con los demás,
            // que sí tienen a quién.
            if ($recipients === []) {
                $io->warning(sprintf(
                    'Nadie recibe el listado de %s: asígnale destinatarios en su ficha o rellena el ajuste general.',
                    $row['node']->getName(),
                ));
                ++$orphan;
                continue;
            }

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
                $io->success(sprintf(
                    'Listado de %s enviado a %s.',
                    $row['node']->getName(),
                    implode(', ', $recipients),
                ));
            } else {
                ++$already;
            }
        }

        if ($sent === 0) {
            $io->note('Nada nuevo que enviar.');

            return $this->nothingToDo(sprintf(
                '%d listado(s) ya enviados, %d sin reparto, %d sin destinatario',
                $already,
                $empty,
                $orphan,
            ));
        }

        return $this->didWork(sprintf(
            '%d listado(s) enviados (%d ya enviados, %d sin reparto, %d sin destinatario)',
            $sent,
            $already,
            $empty,
            $orphan,
        ));
    }

    /**
     * A quién se le manda el listado de este nodo: los suyos propios y, si no
     * tiene ninguno, el ajuste general de respaldo.
     *
     * @param Node     $node     Nodo del reparto.
     * @param string[] $fallback Direcciones de respaldo (ajuste general o --to).
     * @param bool     $forced   Si el destinatario venía impuesto por --to.
     * @return string[]
     */
    private function recipientsFor(Node $node, array $fallback, bool $forced): array
    {
        if ($forced) {
            return $fallback;
        }

        $own = $node->sheetRecipientEmails();

        return $own !== [] ? $own : $fallback;
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
