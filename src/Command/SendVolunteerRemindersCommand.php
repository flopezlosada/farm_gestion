<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\VolunteerSignup;
use App\Repository\UserRepository;
use App\Repository\VolunteerSignupRepository;
use App\Service\AppSettings;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Service\Push\PushSender;
use App\Service\Volunteering\VolunteerOfferFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le recuerda a quien se apuntó a una tarea que le toca, poco antes.
 *
 * Es la diferencia entre apuntarse e ir. Alguien se apunta con dos semanas de
 * antelación, con toda la buena intención, y el jueves no se acuerda; la tarea
 * se queda sin cubrir y encima con la plaza ocupada, que es lo peor de los dos
 * mundos.
 *
 * DISTINTO DEL AVISO QUE PIDE GENTE ({@see SendVolunteerCallsCommand}) y con su
 * propio interruptor: aquél busca a quien no está, éste habla con quien ya dijo
 * que sí. Se puede querer uno sin el otro.
 *
 * Éste SÍ pasa por {@see \App\Service\Cron\EffectLedger}, al revés que las
 * llamadas: allí la repetición la impedía la unicidad (offer, scope) de la
 * BBDD, pero aquí no hay ninguna fila que escribir, y el barrido corre cada
 * hora sobre la misma ventana. Sin el ledger, a quien se apuntó le llegaría el
 * mismo recordatorio veinticuatro veces.
 *
 * EL RECORDATORIO SALE SIEMPRE A LA BANDEJA, y sólo el empujón al móvil respeta
 * la preferencia. Antes, quien había apagado los avisos de voluntariado en el
 * móvil no recibía nada de aquí y el comentario lo justificaba diciendo que la
 * tarea "sigue estando en tu panel": cierto, pero había que acordarse de entrar a
 * buscarla, que es justo lo que este recordatorio existe para evitar.
 */
#[AsCommand(
    name: 'app:send-volunteer-reminders',
    description: 'Recuerda a quien se apuntó a una tarea de voluntariado que le toca.',
)]
class SendVolunteerRemindersCommand extends AbstractCronCommand
{
    /** Clase de efecto con la que se apunta cada recordatorio en el ledger. */
    private const EFFECT_KIND = 'volunteer_reminder';

    public function __construct(
        private readonly VolunteerSignupRepository $signups,
        private readonly UserRepository $users,
        private readonly PushSender $push,
        private readonly NotificationInbox $inbox,
        private readonly NotificationLink $link,
        private readonly NotificationPreferences $preferences,
        private readonly VolunteerOfferFormatter $formatter,
        private readonly AppSettings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Repite un recordatorio aunque ya conste emitido (para uno que no llegó)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista los recordatorios que saldrían, sin enviar nada');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = new \DateTimeImmutable();
        $until = $now->modify(sprintf('+%d hours', $this->settings->getInt(AppSettings::VOLUNTEERING_REMINDER_HOURS)));

        $due = $this->signups->findDueForReminder($now, $until);

        if ((bool) $input->getOption('dry-run')) {
            return $this->preview($io, $due);
        }

        $sent = 0;
        foreach ($due as $signup) {
            $emitted = $this->emitOnce(
                self::EFFECT_KIND,
                fn () => $this->remind($signup),
                $input,
                // Por inscripción: dos personas apuntadas a la misma tarea son
                // dos recordatorios distintos, y la misma persona en dos tareas
                // el mismo día también.
                sprintf('signup-%d', $signup->getId()),
                // Fecha de negocio = la de la tarea, no la de hoy. Así el apunte
                // identifica el recordatorio de ESA tarea aunque el barrido pase
                // varios días seguidos por ella.
                $signup->getOffer()?->getStartsAt() ?? $now,
            );

            if ($emitted) {
                ++$sent;
            }
        }

        return 0 === $sent
            ? $this->nothingToDo('Nadie a quien recordarle nada ahora mismo.')
            : $this->didWork(sprintf('%d recordatorio(s) de voluntariado enviados.', $sent));
    }

    /**
     * Manda el recordatorio de una inscripción.
     *
     * @param VolunteerSignup $signup la inscripción
     */
    private function remind(VolunteerSignup $signup): void
    {
        $offer = $signup->getOffer();
        $partner = $signup->getPartner();

        if (null === $offer || null === $partner) {
            return;
        }

        $recipients = $this->users->findByPartners([$partner]);
        if ([] === $recipients) {
            return;
        }

        $where = $this->formatter->place($offer);
        $title = 'Te toca voluntariado';
        $body = trim(sprintf(
            '%s · %s%s',
            $offer->getTitle(),
            $this->formatter->date($offer->getStartsAt()),
            null !== $where ? ' · '.$where : ''
        ));

        // LA COPIA DE LA BANDEJA, PRIMERO Y SIN MIRAR PREFERENCIAS. Antes, quien
        // había apagado el móvil salía de este método sin nada y el comentario lo
        // justificaba diciendo que "la tarea sigue estando en tu panel": cierto,
        // pero había que acordarse de entrar a buscarla, que es exactamente lo que
        // este recordatorio existe para evitar. Ahora queda dicho en su bandeja.
        $this->inbox->deliver($recipients, Notification::KIND_VOLUNTEERING_REMINDER, $title, $body);

        // El empujón al móvil sí respeta la preferencia: quien se apuntó ya sabe
        // que va, y si ha dicho que no quiere que se le avise por ahí, no se le
        // avisa por ahí.
        if (!$this->preferences->wants($partner, NotificationTopic::VOLUNTEERING, NotificationTopic::CHANNEL_PUSH)) {
            return;
        }

        $this->push->sendToMany(
            $recipients,
            $title,
            $body,
            // Mismo sitio que el destino de la fila de la bandeja: la cadena
            // '/panel/voluntariado' estaba escrita a mano aquí y en el notifier de
            // las llamadas, que es cómo dos avisos del mismo módulo acaban un día
            // llevando a pantallas distintas.
            $this->link->pathForKind(Notification::KIND_VOLUNTEERING_REMINDER),
        );
    }

    /**
     * Enseña a quién se le recordaría, sin mandar nada ni apuntar nada.
     *
     * @param SymfonyStyle           $io  la salida
     * @param list<VolunteerSignup>  $due las inscripciones en ventana
     *
     * @return int código de salida del comando
     */
    private function preview(SymfonyStyle $io, array $due): int
    {
        if ([] === $due) {
            $io->success('Nadie a quien recordarle nada ahora mismo.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($due as $signup) {
            $rows[] = [
                $signup->getOffer()?->getTitle() ?? '—',
                $signup->getOffer()?->getStartsAt()?->format('d/m/Y H:i') ?? '—',
                trim(sprintf('%s %s', $signup->getPartner()?->getName(), $signup->getPartner()?->getSurname())),
            ];
        }

        $io->table(['Tarea', 'Cuándo', 'Quién'], $rows);
        $io->note('Previsualización: no se ha enviado ni apuntado nada. Los que ya constasen emitidos NO se repetirían.');

        return self::SUCCESS;
    }
}
