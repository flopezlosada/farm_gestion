<?php

namespace App\Service\Staff;

use App\Entity\Absence;
use App\Entity\Notification;
use App\Entity\User;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\StaffAudience;
use App\Service\Push\PushSender;

/**
 * Avisos de las ausencias de un trabajador: quien gestiona el personal se entera
 * de lo que pide o registra el trabajador, y el trabajador de lo que se decide.
 *
 * Antes no avisaba nadie: el trabajador pedía vacaciones con un «te avisaremos»
 * que nunca llegaba, y quien aprueba sólo se enteraba si abría la ficha.
 *
 * A QUIEN GESTIONA, BANDEJA A TODOS Y PUSH SÓLO A LOS RESPONSABLES. La bandeja
 * le llega a quien puede decidir, admins incluidos por jerarquía. El push no: a
 * cada admin por cada día de permiso sería ruido, así que va a quien tiene el
 * permiso de personal sin ser admin ({@see StaffAudience::responsiblesFor()}).
 * Si no hay ningún responsable, nadie recibe push; queda la bandeja.
 * AL TRABAJADOR, BANDEJA Y PUSH ({@see WorkerNotifier}): es su respuesta, y «Mi
 * jornada» no tiene otro canal.
 */
class AbsenceNotifier
{
    /** Quién decide las ausencias, el mismo rol que exige aprobarlas. */
    private const ROLE_STAFF = 'ROLE_GESTION_LABORAL_EDIT';

    public function __construct(
        private readonly StaffAudience $staff,
        private readonly WorkerNotifier $workers,
        private readonly NotificationInbox $inbox,
        private readonly PushSender $push,
        private readonly NotificationLink $link,
    ) {
    }

    /**
     * Avisa a quien gestiona el personal de una ausencia creada por el propio
     * trabajador: unas vacaciones pendientes de decidir, o una baja o permiso
     * que ya constan.
     *
     * Se excluye la cuenta del propio trabajador, de los dos canales: si además
     * gestiona el personal, avisarle de lo que acaba de hacer él mismo es ruido.
     *
     * @param Absence $absence La ausencia recién guardada.
     *
     * @return int Cuántos avisos se han dejado en bandejas.
     */
    public function requested(Absence $absence): int
    {
        $worker = $absence->getWorker();
        if (null === $worker) {
            return 0;
        }

        $own = $this->workers->accountOf($worker);
        $notOwn = static fn (User $user): bool => $user->getId() !== $own?->getId();

        $pending = Absence::STATUS_REQUESTED === $absence->getStatus();
        $title = $pending
            ? sprintf('%s pide %s', $worker->getName(), mb_strtolower($this->label($absence)))
            : sprintf('%s ha registrado: %s', $worker->getName(), mb_strtolower($this->label($absence)));
        $body = $this->range($absence) . ($pending ? ' Falta aprobarlas o rechazarlas en su ficha.' : ' Ya consta en su registro.');

        $delivered = $this->inbox->deliver(
            array_values(array_filter($this->staff->withRole(self::ROLE_STAFF), $notOwn)),
            Notification::KIND_STAFF_ABSENCE_REQUESTED,
            $title,
            $body,
        );

        $responsibles = array_values(array_filter($this->staff->responsiblesFor(self::ROLE_STAFF), $notOwn));
        if ([] !== $responsibles) {
            $this->push->sendToMany(
                $responsibles,
                $title,
                $body,
                $this->link->pathForKind(Notification::KIND_STAFF_ABSENCE_REQUESTED),
                'staff_absence_requested',
            );
        }

        return $delivered;
    }

    /**
     * Avisa al trabajador de que su solicitud se ha aprobado o rechazado.
     *
     * @param Absence $absence La ausencia ya decidida.
     *
     * @return int Cuántos avisos se han dejado (0 si no tiene cuenta).
     */
    public function decided(Absence $absence): int
    {
        $worker = $absence->getWorker();
        if (null === $worker) {
            return 0;
        }

        $approved = Absence::STATUS_APPROVED === $absence->getStatus();
        $title = sprintf('Solicitud %s: %s', $approved ? 'aprobada' : 'rechazada', mb_strtolower($this->label($absence)));
        $body = trim($this->range($absence) . ($approved ? '' : ' Si quieres otras fechas, pídelas de nuevo o habla con quien lleva el personal.'));

        return $this->workers->notify($worker, Notification::KIND_WORK_ABSENCE_DECIDED, $title, $body, 'work_absence_decided') ? 1 : 0;
    }

    /**
     * Nombre del tipo de ausencia, el mismo de pantallas y PDF.
     *
     * @param Absence $absence La ausencia.
     *
     * @return string «Vacaciones», «Baja médica»…
     */
    private function label(Absence $absence): string
    {
        return Absence::TYPE_LABELS[$absence->getType()] ?? 'Ausencia';
    }

    /**
     * Las fechas en una frase.
     *
     * @param Absence $absence La ausencia.
     *
     * @return string «Del 03/11/2026 al 07/11/2026.»
     */
    private function range(Absence $absence): string
    {
        $start = $absence->getStartDate();
        $end = $absence->getEndDate();
        if (null === $start || null === $end) {
            return '';
        }

        return $start == $end
            ? sprintf('El %s.', $start->format('d/m/Y'))
            : sprintf('Del %s al %s.', $start->format('d/m/Y'), $end->format('d/m/Y'));
    }
}
