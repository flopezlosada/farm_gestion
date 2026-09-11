<?php

namespace App\Command;

use App\Entity\BasketShare;
use App\Repository\WeeklyBasketRepository;
use App\Service\AppSettings;
use App\Service\Delivery\CancelledDeliveryFilter;
use App\Service\Delivery\PickupNoticeCoverage;
use App\Service\Delivery\PickupReminderMailer;
use App\Service\Delivery\PickupReminderPusher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Envía el recordatorio de recogida a quincenales y mensuales, CONSCIENTE DEL
 * NODO. Lee el modelo MATERIALIZADO (WeeklyBasket con status "recoge") anclando
 * en la FECHA FÍSICA de entrega de cada cesta, no en el viernes-ciclo del
 * Basket. Así respeta skips, traslados y overrides, y —lo importante— cada nodo
 * recibe su aviso el día que le toca:
 *
 *   - Madrid (Cascorro/Midori) recoge el MIÉRCOLES → aviso el lunes.
 *   - La Sierra (Torremocha) recoge el VIERNES → aviso el miércoles.
 *   - Un reparto desplazado por festivo (p. ej. Torremocha al JUEVES) → aviso el
 *     martes, y el email comunica el jueves (con el aviso de desplazamiento).
 *
 * Pensado para correr por cron A DIARIO: resuelve la fecha objetivo como "hoy +
 * N días" (N = antelación configurable en /gestion/settings,
 * {@see AppSettings::PICKUP_REMINDER_DAYS_BEFORE}) y avisa a quien recoja
 * EXACTAMENTE ese día. Si nadie recoge a esa distancia, no manda nada y sale en
 * verde. Se puede forzar una fecha física concreta con --date=YYYY-MM-DD (para
 * pruebas y reenvíos manuales).
 *
 * --dry-run muestra a quién, en qué nodo y qué día avisaría, sin enviar nada.
 */
#[AsCommand(name: 'app:send-pickup-reminders', description: 'Recordatorio de recogida a quincenales/mensuales, por nodo y fecha física.')]
class SendPickupReminderCommand extends AbstractCronCommand
{
    /**
     * Clase de efecto del aviso a administración. Separada de la del propio
     * recordatorio: son dos cosas distintas y una no puede dar la otra por
     * emitida.
     */
    private const COVERAGE_ALERT_KIND = 'coverage_alert';

    public function __construct(
        private readonly WeeklyBasketRepository $weeklyBasketRepository,
        private readonly CancelledDeliveryFilter $cancelledFilter,
        private readonly PickupNoticeCoverage $coverage,
        private readonly AppSettings $settings,
        private readonly PickupReminderMailer $reminderMailer,
        private readonly PickupReminderPusher $reminderPusher,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Fecha física de reparto objetivo (YYYY-MM-DD); ignora la antelación')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual); no afecta a los toggles de email')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Reenvía aunque el aviso ya conste emitido (para correos que no llegaron)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No envía, solo lista destinatarios');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = $this->resolveTargetDate($input, $io);
        if ($target === null) {
            return Command::FAILURE;
        }

        // Los interruptores del correo se leen AQUÍ, antes de tocar nada, y no
        // en el `requires` del manifiesto. Allí inhibían la tarea ENTERA sin que
        // --force lo saltara, y desde que este aviso también sale por push eso
        // dejaba sin avisar a quien lo tenía activado en el móvil, que no ha
        // pedido nada de eso.
        //
        // SE MIRAN LOS DOS, incluido el general, y ésta es la parte que no se
        // puede delegar en el KillSwitchMailer aunque él corte la entrega: el
        // guardián de idempotencia APUNTA EL EFECTO ANTES DE ENVIAR, así que un
        // correo descartado en silencio dejaría el apunte puesto y al reencender
        // los envíos ese recordatorio ya constaría emitido y no saldría nunca.
        // No llamar al mailer es lo único que evita el apunte.
        $emailOn = $this->settings->getBool(AppSettings::EMAIL_ENABLED)
            && $this->settings->getBool(AppSettings::EMAIL_PICKUP_REMINDER);

        if (!$emailOn) {
            $io->note('El recordatorio por email está desactivado en /gestion/settings: no se envía ningún correo ni se apunta como enviado. El aviso al móvil no depende de ese ajuste y sigue su curso.');
        }

        // Las listas COMPLETAS por cadencia, no las constantes sueltas de la
        // modalidad "normal". Quien comparte cesta con otra familia (quincenal
        // compartida, mensual compartida) va a recogerla el mismo día y con la
        // misma cadencia que quien no la comparte: compartir es un acuerdo
        // administrativo, no otra forma de repartir. Pidiendo sólo
        // ID_BIWEEKLY/ID_MONTHLY esas familias quedaban fuera de la consulta y
        // nunca recibían el recordatorio.
        $recipients = $this->cancelledFilter->withoutCancelled(
            $this->weeklyBasketRepository->findPickedByDeliveryDateAndShares(
                $target,
                [...BasketShare::IDS_BIWEEKLY, ...BasketShare::IDS_MONTHLY],
            )
        );

        $io->section(sprintf('Reparto físico del %s', $target->format('Y-m-d')));

        if (empty($recipients)) {
            $io->note(sprintf('Nadie en modalidad quincenal o mensual recoge el %s. No se envía nada.', $target->format('Y-m-d')));
            return $this->nothingToDo(sprintf('Nadie recoge el %s', $target->format('Y-m-d')));
        }

        $io->table(
            ['Socix', 'Email', 'Modalidad', 'Nodo', 'Recogida', 'Desplazado'],
            array_map(function ($wb) {
                $ctx = $this->reminderMailer->contextFor($wb);
                return [
                    trim($wb->getPartner()->getName() . ' ' . $wb->getPartner()->getSurname()),
                    $wb->getPartner()->getEmail() ?: '(sin email)',
                    $ctx['modality'],
                    $ctx['node_name'] ?? '(sin nodo)',
                    $ctx['pickup_date']->format('Y-m-d'),
                    $ctx['was_shifted'] ? 'sí' : '',
                ];
            }, $recipients),
        );

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Dry-run: %d destinatarios. No se ha enviado nada.', count($recipients)));
            return Command::SUCCESS;
        }

        $resend = (bool) $input->getOption('resend');

        // LA COPIA DE LA BANDEJA VA PRIMERA, antes del correo y del push, y no
        // depende de ningún toggle ni de las preferencias del socix. Es el suelo
        // del aviso: lo que queda cuando alguien ha apagado los otros dos canales,
        // y lo que permite que la pantalla de avisos prometa que apagarlos no
        // pierde información. Escribirla antes es lo que impide que un correo que
        // no sale o un push que se cae se lleven el aviso por delante.
        $inbox = $this->reminderPusher->recordInbox($recipients, $resend);

        $result = $emailOn
            ? $this->reminderMailer->send($recipients, $resend)
            : ['sent' => 0, 'skipped' => 0, 'already' => 0];

        // El push va DESPUÉS del correo y con su propio apunte de idempotencia:
        // son dos canales independientes, así que quien acaba de activar los
        // avisos en el móvil los recibe aunque el correo de ese día ya se
        // hubiera mandado, y un fallo del push no puede deshacer un correo que
        // ya salió. Quien no tenga ningún navegador suscrito no cuenta como
        // fallo: el push es un extra sobre el correo, no su sustituto.
        $pushed = $this->reminderPusher->send($recipients, $resend);

        $io->success(sprintf(
            'Enviados %d email(s). %d socixs sin email. %d ya estaban avisados.',
            $result['sent'],
            $result['skipped'],
            $result['already'],
        ));

        if ($pushed['sent'] > 0 || $pushed['devices'] > 0) {
            $io->success(sprintf(
                'Avisos al móvil: %d socix(s), %d navegador(es). %d ya estaban avisados.',
                $pushed['sent'],
                $pushed['devices'],
                $pushed['already'],
            ));
        }

        if ($inbox['written'] > 0) {
            $io->success(sprintf(
                'Bandeja de avisos: %d copia(s) dejadas. %d ya la tenían.',
                $inbox['written'],
                $inbox['already'],
            ));
        }

        // LA TAREA SE MIRA A SÍ MISMA AL TERMINAR, y no es adorno: es lo único
        // que convierte este fallo en visible el mismo día. Cuando el
        // recordatorio dejó fuera a las cestas compartidas, esta tarea salió en
        // verde dos meses seguidos porque hacía exactamente lo que le habían
        // pedido; lo que faltaba era alguien preguntando si lo que le habían
        // pedido alcanzaba a todo el mundo.
        //
        // Se pregunta DESPUÉS de enviar y a quien no comparte su criterio de
        // selección ({@see PickupNoticeCoverage}), así que un hueco aquí
        // significa gente que recogía ese día, que tenía por dónde recibir el
        // aviso, y a la que esta misma ejecución no ha alcanzado.
        $cobertura = $this->coverage->forDate($target);
        $huecos = $cobertura['totals']['gap'];
        $aviso = '';
        if ($huecos > 0) {
            $aviso = sprintf(' · ⚠ %d sin avisar', $huecos);
            $io->warning(sprintf(
                '%d socix(s) recogen el %s, pueden recibir el aviso y NO se les ha avisado. '
                . 'Míralo en Registro de avisos → Cobertura: si se repite en varios repartos, '
                . 'el recordatorio está dejando fuera a alguien de forma sistemática.',
                $huecos,
                $target->format('Y-m-d'),
            ));

            $aviso .= $this->alertarAdministracion($io, $input, $target, $cobertura, $huecos);
        }

        // Un push mandado también es trabajo hecho, y una copia en la bandeja
        // también: si sólo se mira el correo, el día en que todos los emails ya
        // constaran pero la copia se escribiera por primera vez, el planificador
        // lo apuntaría como "nada que hacer" y el registro de /gestion/settings
        // mentiría sobre lo que de verdad pasó. Con el correo apagado, la copia es
        // además lo ÚNICO que se hace, y sin contarla la tarea saldría siempre en
        // "nada que hacer".
        if ($result['sent'] > 0 || $pushed['sent'] > 0 || $inbox['written'] > 0) {
            return $this->didWork(sprintf(
                '%d recordatorios enviados para el %s (%d sin email, %d ya avisados) · %d aviso(s) al móvil · %d en la bandeja%s',
                $result['sent'],
                $target->format('Y-m-d'),
                $result['skipped'],
                $result['already'],
                $pushed['sent'],
                $inbox['written'],
                $aviso,
            ));
        }

        // Sin envíos nuevos la tarea corrió sana, y el motivo importa: que todos
        // estuvieran ya avisados es el caso normal de una segunda pasada del
        // reloj, no una avería.
        return $this->nothingToDo(($result['already'] > 0
            ? sprintf('%d destinatarios el %s, todos avisados ya', $result['already'], $target->format('Y-m-d'))
            : sprintf('%d destinatarios el %s, ninguno con email', $result['skipped'], $target->format('Y-m-d'))) . $aviso);
    }

    /**
     * Escribe a administración contando que alguien se ha quedado sin aviso.
     *
     * SÓLO SE MANDA CUANDO HAY HUECO, y ésa es la decisión de diseño: un correo
     * periódico que casi siempre dice "todo bien" deja de leerse en dos
     * semanas, y entonces no sirve para nada. Que llegue significa que hay algo
     * que mirar.
     *
     * Una vez por reparto, aunque el reloj repita la tarea: lo garantiza el
     * guardián de idempotencia con la fecha del REPARTO como clave, no la de
     * hoy. Si no, un tick horario mandaría el mismo aviso cada hora.
     *
     * Con el correo apagado no se intenta siquiera. Puede sonar a que es cuando
     * más falta hace, pero es al revés: si los envíos están apagados, el aviso
     * tampoco saldría, y el guardián lo daría por emitido para siempre. El
     * hueco queda igualmente en la pantalla y en el registro.
     *
     * @param array<string, mixed> $cobertura Resultado de {@see PickupNoticeCoverage::forDate()}.
     * @return string Coletilla para el resumen de la ejecución.
     */
    private function alertarAdministracion(
        SymfonyStyle $io,
        InputInterface $input,
        \DateTimeImmutable $target,
        array $cobertura,
        int $huecos,
    ): string {
        if (!$this->settings->getBool(AppSettings::EMAIL_ENABLED)
            || !$this->settings->getBool(AppSettings::EMAIL_COVERAGE_ALERT)) {
            $io->note('El aviso a administración está desactivado: el hueco queda en el registro y en la pantalla de cobertura.');

            return '';
        }

        $to = (string) $this->settings->getString(AppSettings::EMAIL_ADMIN_DELIVERY_SUMMARY_TO);
        $recipients = array_values(array_filter(array_map('trim', explode(',', $to))));

        if ($recipients === []) {
            $io->note('Hay hueco pero no hay a quién avisar: configura el destinatario en /gestion/settings.');

            return '';
        }

        $message = (new TemplatedEmail())
            ->to(...$recipients)
            ->subject(sprintf(
                'CSA Vega · %d socix(s) sin aviso de su cesta del %s',
                $huecos,
                $target->format('d/m/Y'),
            ))
            ->htmlTemplate('email/coverage_alert.html.twig')
            ->textTemplate('email/coverage_alert.txt.twig')
            ->context([
                'gap' => $huecos,
                'pickup_date' => $target,
                'rows' => $cobertura['rows'],
                'coverage_url' => $this->urlGenerator->generate(
                    'notification_log_coverage',
                    ['date' => $target->format('Y-m-d')],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ]);

        $emitido = $this->emitOnce(
            self::COVERAGE_ALERT_KIND,
            fn () => $this->mailer->send($message),
            $input,
            on: $target,
            target: implode(', ', $recipients),
        );

        if (!$emitido) {
            return '';
        }

        $io->success(sprintf('Avisado a %s del hueco.', implode(', ', $recipients)));

        return ' (avisada administración)';
    }

    /**
     * Fecha física objetivo: --date=YYYY-MM-DD si se indica (para pruebas y
     * reenvíos), o "hoy + antelación configurada" en el camino del cron diario.
     * Devuelve null (y pinta el error) si --date no es una fecha válida.
     */
    private function resolveTargetDate(InputInterface $input, SymfonyStyle $io): ?\DateTimeImmutable
    {
        $date = $input->getOption('date');
        if ($date !== null) {
            // Parseo ESTRICTO: '!Y-m-d' fija la hora a 00:00 y, con getLastErrors,
            // rechaza tanto formatos inválidos como fechas imposibles (2026-02-30,
            // que new \DateTimeImmutable() aceptaría haciendo rollover silencioso a
            // marzo y avisaría del día equivocado).
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                $io->error(sprintf('Fecha inválida en --date: "%s". Formato esperado YYYY-MM-DD.', $date));
                return null;
            }

            return $parsed;
        }

        $daysBefore = $this->settings->getInt(AppSettings::PICKUP_REMINDER_DAYS_BEFORE);

        return (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $daysBefore));
    }
}
