<?php

namespace App\Command;

use App\Entity\Basket;
use App\Entity\PartnerBasketShare;
use App\Repository\DeliveryExceptionRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Envía recordatorio por email a quincenales y mensuales que TOCAN un viernes
 * concreto. Pensado para correr por cron miércoles tarde. Por defecto resuelve
 * el viernes objetivo como "el próximo viernes con cesta creada"; se puede
 * forzar con --basket=ID o --date=YYYY-MM-DD.
 *
 * --dry-run muestra a quién enviaría sin enviar nada.
 */
#[AsCommand(name: 'app:send-pickup-reminders', description: 'Recordatorio a quincenales/mensuales del próximo viernes.')]
class SendPickupReminderCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerBasketShareRepository $shareRepository,
        private readonly DeliveryExceptionRepository $exceptionRepository,
        private readonly WeeklyBasketGenerator $generator,
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('basket', null, InputOption::VALUE_REQUIRED, 'ID de Basket concreto')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Fecha del viernes (YYYY-MM-DD)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No envía, solo lista destinatarios');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $basket = $this->resolveBasket($input);
        if ($basket === null) {
            $io->error('No se ha podido resolver una cesta objetivo. Usa --basket=ID o --date=YYYY-MM-DD.');
            return Command::FAILURE;
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

        // Próximo Basket con fecha >= hoy.
        return $this->em->createQueryBuilder()
            ->select('b')
            ->from(Basket::class, 'b')
            ->where('b.date >= :today')
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Devuelve los destinatarios para este viernes: solo quincenales y
     * mensuales a los que LES TOCA. Usa los mismos métodos del repositorio
     * que la pantalla de reparto, así no se duplica lógica.
     *
     * @return array<int, array{partner: \App\Entity\Partner, modality: string}>
     */
    private function resolveRecipients(Basket $basket): array
    {
        $dayOrder = \App\Custom\WeekOfMonth::dayOfWeekInMonth($basket->getDate());

        $biweekly = $this->shareRepository->findBasketPartnersBiweeklyAndCity($basket, 2, 1);
        $monthly = $this->shareRepository->findBasketPartnersMonthlyAndCity($basket, 3, $dayOrder);

        $recipients = [];
        foreach ($biweekly as $share) {
            $recipients[] = ['partner' => $share->getPartner(), 'modality' => 'quincenal'];
        }
        foreach ($monthly as $share) {
            $recipients[] = ['partner' => $share->getPartner(), 'modality' => 'mensual'];
        }
        return $recipients;
    }
}
