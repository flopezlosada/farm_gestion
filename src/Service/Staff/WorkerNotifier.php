<?php

namespace App\Service\Staff;

use App\Entity\User;
use App\Entity\Worker;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Push\PushSender;

/**
 * Deja un aviso a un trabajador en la bandeja de su cuenta y se lo empuja al
 * móvil.
 *
 * Los dos canales siempre juntos, sin preferencias por tema: todo lo que se le
 * avisa a un trabajador es de su propia jornada, y «Mi jornada» no tiene otro
 * sitio donde enterarse. La bandeja es el suelo (no se apaga); el push sólo llega
 * si ese trabajador lo activó en su navegador.
 */
class WorkerNotifier
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly NotificationInbox $inbox,
        private readonly PushSender $push,
        private readonly NotificationLink $link,
    ) {
    }

    /**
     * Avisa a un trabajador.
     *
     * @param Worker      $worker   A quién.
     * @param string      $kind     Constante Notification::KIND_*; decide a dónde lleva.
     * @param string      $title    Lo que se lee de un vistazo.
     * @param string|null $body     El detalle.
     * @param string      $logKind  Clase de aviso para la bitácora del push.
     *
     * @return bool false si el trabajador no tiene cuenta y no hay a quién avisar.
     */
    public function notify(Worker $worker, string $kind, string $title, ?string $body, string $logKind): bool
    {
        $account = $this->accountOf($worker);
        if (null === $account) {
            return false;
        }

        $this->inbox->deliver([$account], $kind, $title, $body);
        $this->push->sendToUser($account, $title, $body, $this->link->pathForKind($kind), $logKind);

        return true;
    }

    /**
     * La cuenta con la que entra el trabajador, si tiene.
     *
     * @param Worker $worker El trabajador.
     *
     * @return User|null Su cuenta, o null si no tiene acceso.
     */
    public function accountOf(Worker $worker): ?User
    {
        return $this->users->findOneBy(['worker' => $worker]);
    }
}
