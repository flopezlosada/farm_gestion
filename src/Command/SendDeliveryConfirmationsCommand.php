<?php

namespace App\Command;

use App\Entity\Partner;
use App\Service\AppSettings;
use App\Service\Delivery\DeliveryConfirmationMailer;
use App\Service\Delivery\DeliverySheetSchedule;
use App\Service\Delivery\PickupContents;
use App\Service\Delivery\PickupNotice;
use App\Service\Delivery\PickupNoticePusher;
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
 *
 * Avisa por TRES vías —correo, móvil y bandeja— porque es el único aviso del
 * reparto una vez cerrado el plazo: el recordatorio calla entonces para que nadie
 * reciba dos veces lo mismo. La bandeja va primero y sin mirar toggles ni
 * preferencias (es el suelo); el correo, sólo con sus interruptores encendidos;
 * el móvil, a quien lo tenga activado.
 */
#[AsCommand(name: 'app:send-delivery-confirmations', description: 'Confirma a cada socix su cesta en cuanto cierra el plazo de su nodo.')]
class SendDeliveryConfirmationsCommand extends AbstractCronCommand
{
    public function __construct(
        private readonly DeliverySheetSchedule $schedule,
        private readonly DeliveryConfirmationMailer $confirmationMailer,
        private readonly PickupNoticePusher $noticePusher,
        private readonly AppSettings $settings,
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

        // Sin congelar NO se confirma nada, y aquí es más grave que en el listado:
        // la audiencia se lee del modelo materializado, así que sobre una semana
        // sin congelar sólo saldrían quienes movieron algo y el resto del nodo se
        // quedaría sin noticia. Escribir a tres de sesenta es peor que no escribir.
        $thawed = array_values(array_filter($pending, static fn (array $row): bool => !$row['frozen']));
        $pending = array_values(array_filter($pending, static fn (array $row): bool => $row['frozen']));

        foreach ($thawed as $row) {
            $io->warning(sprintf(
                'El reparto de %s del %s ha cerrado su plazo pero su semana NO está congelada: no se confirma nada.',
                $row['node']->getName(),
                $row['physical_date']->format('Y-m-d'),
            ));
        }

        if ($pending === []) {
            return $this->nothingToDo(sprintf('%d reparto(s) cerrados pero sin congelar', count($thawed)));
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
                    $entry['contents'] ? 'recoge ' . $entry['contents']->label() : 'no recoge',
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

        // Los DOS interruptores se miran aquí y no en `requires` del manifiesto:
        // aquél inhibe la tarea entera, y apagar el correo dejaría sin aviso a
        // quien lo tiene activado en el móvil. Y no se delega en el KillSwitchMailer
        // aunque él corte la entrega: el guardián de idempotencia apunta el efecto
        // ANTES de enviar, así que un correo descartado en silencio quedaría como
        // emitido y no saldría al reencender. No llamar al mailer es lo único que
        // evita el apunte.
        $emailOn = $this->settings->getBool(AppSettings::EMAIL_ENABLED)
            && $this->settings->getBool(AppSettings::EMAIL_DELIVERY_CONFIRMATION);

        if (!$emailOn) {
            $io->note('La confirmación por email está desactivada en /gestion/settings: no se envía ningún correo ni se apunta como enviado. El aviso al móvil y la bandeja siguen su curso.');
        }

        $sent = 0;
        $skipped = 0;
        $already = 0;
        $pushed = 0;
        $written = 0;
        foreach ($audiences as $audience) {
            $notices = $this->noticesFor($audience);

            $written += $this->noticePusher->recordInbox($notices, DeliveryConfirmationMailer::EFFECT_KIND_INBOX, $resend)['written'];

            if ($emailOn) {
                $result = $this->confirmationMailer->send($audience, $resend);
                $sent += $result['sent'];
                $skipped += $result['skipped'];
                $already += $result['already'];
            }

            $pushed += $this->noticePusher->send(
                $notices,
                DeliveryConfirmationMailer::EFFECT_KIND_PUSH,
                DeliveryConfirmationMailer::EFFECT_KIND,
                $resend,
            )['sent'];
        }

        $summary = sprintf(
            '%d confirmación(es) por email (%d sin email, %d ya avisados) · %d al móvil · %d en la bandeja',
            $sent,
            $skipped,
            $already,
            $pushed,
            $written,
        );

        $io->success($summary);

        // Un push o una copia en la bandeja también son trabajo hecho: con el
        // correo apagado son lo ÚNICO que se hace, y sin contarlos la tarea
        // saldría siempre en "nada que hacer".
        return $sent > 0 || $pushed > 0 || $written > 0
            ? $this->didWork($summary)
            : $this->nothingToDo($summary);
    }

    /**
     * La confirmación de cada destinatarix, en la forma que entienden el móvil y
     * la bandeja. Quien no recoge va sin contenido, y eso es lo que le dice que
     * su cambio se aplicó.
     *
     * @param list<array{partner: Partner, contents: ?PickupContents, pickup_date: \DateTimeImmutable, node_name: ?string, was_shifted: bool}> $audience
     * @return list<PickupNotice>
     */
    private function noticesFor(array $audience): array
    {
        return array_map(
            static fn (array $entry): PickupNotice => new PickupNotice(
                $entry['partner'],
                $entry['contents']?->label(),
                $entry['pickup_date'],
                $entry['node_name'],
                $entry['was_shifted'],
            ),
            $audience,
        );
    }
}
