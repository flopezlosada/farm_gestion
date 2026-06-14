<?php

namespace App\Command;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Repository\DeliveryExceptionRepository;
use App\Repository\WeeklyBasketRepository;
use App\Security\PartnerAccessPolicy;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Envía recordatorio por email a quincenales y mensuales que TOCAN un viernes
 * concreto, leyendo el modelo MATERIALIZADO (WeeklyBasket con status "recoge"):
 * así respeta skips, traslados y overrides — quien marcó "no recojo" no recibe
 * el aviso.
 *
 * Pensado para correr por cron A DIARIO: por defecto resuelve la cesta objetivo
 * como "la del reparto que cae dentro de N días" (N = antelación configurable
 * en /gestion/settings, {@see AppSettings::PICKUP_REMINDER_DAYS_BEFORE}).
 * Si hoy no hay reparto a esa distancia, no manda nada y sale en verde. Se puede
 * forzar una cesta concreta con --basket=ID o --date=YYYY-MM-DD (que ignoran la
 * antelación, para pruebas y reenvíos manuales).
 *
 * --dry-run muestra a quién enviaría sin enviar nada.
 */
#[AsCommand(name: 'app:send-pickup-reminders', description: 'Recordatorio a quincenales/mensuales del próximo viernes.')]
class SendPickupReminderCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketRepository $weeklyBasketRepository,
        private readonly DeliveryExceptionRepository $exceptionRepository,
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
        private readonly PartnerAccessPolicy $accessPolicy,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('basket', null, InputOption::VALUE_REQUIRED, 'ID de Basket concreto')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Fecha del viernes (YYYY-MM-DD)')
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

        $basket = $this->resolveBasket($input);
        if ($basket === null) {
            // Override explícito que no casa con nada ⇒ error; sin override es
            // simplemente "hoy no toca" (cron diario): verde, sin ruido.
            if ($input->getOption('basket') !== null || $input->getOption('date') !== null) {
                $io->error('No se ha podido resolver la cesta objetivo: --basket/--date no casan con ninguna cesta.');
                return Command::FAILURE;
            }

            $days = $this->settings->getInt(AppSettings::PICKUP_REMINDER_DAYS_BEFORE);
            $io->note(sprintf('Hoy no toca recordatorio: no hay reparto dentro de %d día(s).', $days));
            return Command::SUCCESS;
        }

        $io->section(sprintf('Cesta #%d · viernes %s', $basket->getId(), $basket->getDate()->format('Y-m-d')));

        // Si hay una excepción de calendario que cancela el viernes, no se manda nada.
        $exception = $this->exceptionRepository->findGlobalForBasket($basket);
        if ($exception !== null && $exception->isCancelled()) {
            $io->warning('Ese viernes está marcado como SIN REPARTO. No se envía nada.');
            return Command::SUCCESS;
        }

        $recipients = $this->resolveRecipients($basket);
        if (empty($recipients)) {
            $io->note('Nadie en modalidad quincenal o mensual recibe cesta este viernes.');
            return Command::SUCCESS;
        }

        $io->table(['Socix', 'Email', 'Modalidad'], array_map(
            fn (array $r) => [
                $r['partner']->getName() . ' ' . $r['partner']->getSurname(),
                $r['partner']->getEmail() ?: '(sin email)',
                $r['modality'],
            ],
            $recipients
        ));

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('Dry-run: %d destinatarios. No se ha enviado nada.', count($recipients)));
            return Command::SUCCESS;
        }

        // Master switch de enlaces: con él apagado el email es puramente
        // informativo (sin botón de acción) para todo el mundo.
        $linksEnabled = $this->settings->getBool(AppSettings::EMAIL_PICKUP_REMINDER_LINKS);

        $sent = 0;
        $skipped = 0;
        foreach ($recipients as $r) {
            $email = $r['partner']->getEmail();
            if (!$email) {
                $skipped++;
                continue;
            }

            $message = (new TemplatedEmail())
                ->to($email)
                ->subject('Recordatorio: tu cesta de la CSA Vega de Jarama este viernes')
                ->htmlTemplate('email/pickup_reminder.html.twig')
                ->textTemplate('email/pickup_reminder.txt.twig')
                ->context([
                    'partner' => $r['partner'],
                    'modality' => $r['modality'],
                    'pickup_date' => $exception?->getShiftedDate() ?? $basket->getDate(),
                    'was_shifted' => $exception !== null && !$exception->isCancelled(),
                    // Los enlaces de acción exigen login: solo se pintan si el
                    // master switch está encendido Y el socix puede entrar
                    // (tiene cuenta, o el alta está abierta). canUseActionLinks
                    // hace 1 SELECT a user por destinatario; asumido a propósito
                    // con ~decenas de envíos por semana.
                    'can_act' => $linksEnabled && $this->accessPolicy->canUseActionLinks($r['partner']),
                    'calendar_url' => $this->urlGenerator->generate('panel_calendar', [], UrlGeneratorInterface::ABSOLUTE_URL),
                ]);

            $this->mailer->send($message);
            $sent++;
        }

        $io->success(sprintf('Enviados %d email(s). %d socixs sin email.', $sent, $skipped));
        return Command::SUCCESS;
    }

    private function resolveBasket(InputInterface $input): ?Basket
    {
        $basketId = $input->getOption('basket');
        if ($basketId !== null) {
            return $this->em->getRepository(Basket::class)->find((int) $basketId);
        }

        $date = $input->getOption('date');
        if ($date !== null) {
            return $this->em->getRepository(Basket::class)->findOneBy(['date' => new \DateTime($date)]);
        }

        // Sin override: la cesta del reparto que cae exactamente dentro de N
        // días (antelación configurable). Con cron diario, esto dispara el
        // recordatorio una sola vez por ciclo, el día que toca.
        $daysBefore = $this->settings->getInt(AppSettings::PICKUP_REMINDER_DAYS_BEFORE);
        $target = (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $daysBefore));

        return $this->em->getRepository(Basket::class)->findOneBy(['date' => new \DateTime($target->format('Y-m-d'))]);
    }

    /**
     * Destinatarios para este viernes: solo quincenales y mensuales a los que
     * LES TOCA. Se leen del modelo MATERIALIZADO (WeeklyBasket con status
     * "recoge"), no de los finders legacy por modalidad — así un socix que
     * marcó "no recojo", se trasladó o tiene un override queda reflejado
     * correctamente (el que no recoge no tiene WeeklyBasket activa y no recibe
     * aviso). Lxs semanales no entran: recogen cada semana y no necesitan
     * recordatorio.
     *
     * @return array<int, array{partner: \App\Entity\Partner, modality: string}>
     */
    private function resolveRecipients(Basket $basket): array
    {
        $modalityByShare = [
            BasketShare::ID_BIWEEKLY => 'quincenal',
            BasketShare::ID_MONTHLY => 'mensual',
        ];

        $picked = $this->weeklyBasketRepository->findPickedForBasketByShares(
            $basket,
            array_keys($modalityByShare),
        );

        return array_map(
            fn ($wb) => [
                'partner' => $wb->getPartner(),
                'modality' => $modalityByShare[$wb->getBasketShare()->getId()],
            ],
            $picked,
        );
    }
}
