<?php

namespace App\Command;

use App\Entity\BasketShare;
use App\Entity\WeeklyBasket;
use App\Repository\DeliveryExceptionRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\AppSettings;
use App\Service\Delivery\PickupReminderMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
class SendPickupReminderCommand extends Command
{
    public function __construct(
        private readonly WeeklyBasketRepository $weeklyBasketRepository,
        private readonly DeliveryExceptionRepository $exceptionRepository,
        private readonly AppSettings $settings,
        private readonly PickupReminderMailer $reminderMailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Fecha física de reparto objetivo (YYYY-MM-DD); ignora la antelación')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual); no afecta a los toggles de email')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No envía, solo lista destinatarios');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Gate de la tarea programada: apagada en /gestion/settings, la tarea ni
        // siquiera calcula destinatarios (sale en verde para no disparar alertas
        // del cron). El dry-run y --force (ejecución manual explícita) la saltan.
        if (!$input->getOption('dry-run') && !$input->getOption('force') && !$this->settings->getBool(AppSettings::CRON_PICKUP_REMINDER)) {
            $io->warning('La tarea programada del recordatorio está desactivada en /gestion/settings. No se ejecuta.');
            return Command::SUCCESS;
        }

        // Toggle de configuración: con el envío apagado el cron no manda nada
        // (y sale en verde para no disparar alertas). El dry-run sigue
        // funcionando para poder probar destinatarios con el toggle apagado.
        if (!$input->getOption('dry-run') && !$this->settings->getBool(AppSettings::EMAIL_PICKUP_REMINDER)) {
            $io->warning('El recordatorio de recogida está desactivado en /gestion/settings. No se envía nada.');
            return Command::SUCCESS;
        }

        $target = $this->resolveTargetDate($input, $io);
        if ($target === null) {
            return Command::FAILURE;
        }

        $recipients = $this->withoutCancelled(
            $this->weeklyBasketRepository->findPickedByDeliveryDateAndShares(
                $target,
                [BasketShare::ID_BIWEEKLY, BasketShare::ID_MONTHLY],
            )
        );

        $io->section(sprintf('Reparto físico del %s', $target->format('Y-m-d')));

        if (empty($recipients)) {
            $io->note(sprintf('Nadie en modalidad quincenal o mensual recoge el %s. No se envía nada.', $target->format('Y-m-d')));
            return Command::SUCCESS;
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

        $result = $this->reminderMailer->send($recipients);

        $io->success(sprintf('Enviados %d email(s). %d socixs sin email.', $result['sent'], $result['skipped']));
        return Command::SUCCESS;
    }

    /**
     * Descarta las WeeklyBasket cuyo reparto está CANCELADO por una excepción de
     * calendario. Al materializar de cero, un ciclo cancelado no genera WB (el
     * generador hace `continue` cuando la fecha física es null). Pero una
     * cancelación registrada DESPUÉS de materializar la semana no limpia
     * retroactivamente el WB ya creado salvo que se reconcilie: queda vivo (status
     * "recoge") con delivery_date en el día cancelado. El finder ancla en
     * delivery_date, así que sin este filtro el recordatorio avisaría de un
     * reparto que no existe. Es la misma protección que aplica en la vista el
     * {@see \App\Service\Delivery\DeliveryCalendarProjector}. La precedencia
     * (cancelación global absoluta, si no la del nodo) la resuelve
     * {@see DeliveryExceptionRepository::findForBasketAndNode()}. Se cachea por
     * (basket, nodo): los destinatarios de un mismo día comparten ciclo y pocos nodos.
     *
     * @param WeeklyBasket[] $recipients
     * @return WeeklyBasket[]
     */
    private function withoutCancelled(array $recipients): array
    {
        $cancelledByKey = [];

        return array_values(array_filter($recipients, function (WeeklyBasket $wb) use (&$cancelledByKey): bool {
            $basket = $wb->getBasket();
            if ($basket === null) {
                return true;
            }

            $node = $wb->getWeeklyBasketGroup()?->getNode();
            $key = $basket->getId() . ':' . ($node?->getId() ?? 'global');
            if (!array_key_exists($key, $cancelledByKey)) {
                $exception = $node !== null
                    ? $this->exceptionRepository->findForBasketAndNode($basket, $node)
                    : $this->exceptionRepository->findGlobalForBasket($basket);
                $cancelledByKey[$key] = $exception !== null && $exception->isCancelled();
            }

            return !$cancelledByKey[$key];
        }));
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
