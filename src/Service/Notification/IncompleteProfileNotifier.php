<?php

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use App\Service\Partner\PartnerProfileCompleteness;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Persigue las fichas de socix a las que les faltan datos, por los dos lados: al
 * socix se le pide lo que él puede rellenar, y a quien coordina socixs se le dice
 * cuántas fichas están a medias.
 *
 * SÓLO POR LA BANDEJA, sin correo ni push, y es la decisión de fondo. Un dato que
 * falta no es urgente: no tiene un día ni una hora, y lleva ahí meses. Empujarlo
 * al móvil o al correo sería el aviso que enseña a ignorar los avisos, y el que
 * hace que el siguiente —"hoy recoges tu cesta"— llegue a una persona que ya no
 * los mira. En la bandeja espera sin molestar hasta que se entra.
 *
 * SE RENUEVA CADA SEMANA, PERO SÓLO SI EL ANTERIOR SE LEYÓ. Ésa es la regla, y no
 * "uno por semana": si el aviso sigue sin abrir, ya está diciendo lo que tiene que
 * decir y otro igual encima sólo infla la campanita. Si se leyó y la ficha sigue a
 * medias, el lunes siguiente vuelve. La condición sale de la propia tabla
 * ({@see NotificationRepository::hasUnreadOfKind()}) y no de un apunte semanal en
 * {@see \App\Service\Cron\EffectLedger}: el ledger sabe si aquella semana se
 * avisó, pero no si la persona lo ha leído, que es justo lo que decide.
 *
 * AL SOCIX SÓLO SE LE PIDE LO QUE PUEDE ARREGLAR. Su formulario de datos no toca
 * el teléfono ni el correo —los cambia administración—, así que a quien sólo le
 * falte uno de esos dos no se le avisa: sería un aviso que no puede cerrar y que
 * le volvería cada semana durante años. Su hueco se persigue por el otro lado, en
 * el aviso de quien coordina.
 *
 * A QUIEN COORDINA LE LLEGA UN AVISO RESUMEN, no uno por socix. Con cuarenta
 * fichas a medias, cuarenta avisos que vuelven cada semana no se leen: se apagan.
 * El resumen dice cuántas son y lleva al listado, donde están todas con lo que le
 * falta a cada una.
 */
class IncompleteProfileNotifier
{
    /** El permiso que hace a alguien destinatario del aviso de fichas a medias. */
    private const COORDINATOR_ROLE = 'ROLE_GESTION_SOCIXS';

    public function __construct(
        private readonly PartnerRepository $partners,
        private readonly UserRepository $users,
        private readonly NotificationRepository $notifications,
        private readonly NotificationInbox $inbox,
        private readonly PartnerProfileCompleteness $completeness,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * Mira todas las fichas y deja los avisos que toquen.
     *
     * @return array{partners: int, incomplete: int, coordinators: int} avisos a socixs, fichas a medias y avisos a quien coordina
     */
    public function notify(): array
    {
        $incomplete = $this->incompleteProfiles();

        return [
            'partners' => $this->notifyPartners($incomplete),
            'incomplete' => \count($incomplete),
            'coordinators' => $this->notifyCoordinators(\count($incomplete)),
        ];
    }

    /**
     * Las fichas activas a las que les falta algo importante, con la lista de lo
     * que falta.
     *
     * Público porque lo usa también el listado de gestión: la lista que se enseña
     * y la que se cuenta en el aviso tienen que ser LA MISMA, o el aviso dirá doce
     * y la pantalla enseñará nueve.
     *
     * @return list<array{partner: Partner, missing: list<string>}> las fichas a medias
     */
    public function incompleteProfiles(): array
    {
        $incomplete = [];

        foreach ($this->partners->findActiveForProfileReview() as $partner) {
            if (!$this->completeness->applies($partner)) {
                continue;
            }

            $missing = $this->completeness->missing($partner);
            if ([] !== $missing) {
                $incomplete[] = ['partner' => $partner, 'missing' => $missing];
            }
        }

        return $incomplete;
    }

    /**
     * Avisa a cada socix de lo que le falta y puede rellenar él.
     *
     * @param list<array{partner: Partner, missing: list<string>}> $incomplete las fichas a medias
     *
     * @return int cuántos avisos se han dejado
     */
    private function notifyPartners(array $incomplete): int
    {
        $partners = array_map(static fn (array $row): Partner => $row['partner'], $incomplete);
        $usersByPartner = $this->usersByPartner($partners);

        $written = 0;
        foreach ($incomplete as $row) {
            $partner = $row['partner'];

            // Lo que puede arreglar él. Si sólo le falta el teléfono o el correo
            // esto viene vacío y no se le dice nada: no tiene dónde cambiarlo.
            $mine = $this->completeness->missingSelfService($partner);
            if ([] === $mine) {
                continue;
            }

            foreach ($usersByPartner[(int) $partner->getId()] ?? [] as $user) {
                if ($this->notifications->hasUnreadOfKind($user, Notification::KIND_PROFILE_INCOMPLETE)) {
                    continue;
                }

                $this->inbox->record(
                    $user,
                    Notification::KIND_PROFILE_INCOMPLETE,
                    'Faltan datos en tu ficha',
                    sprintf(
                        'Nos falta %s. Puedes rellenarlo tú en «Mis datos», y así el reparto y los avisos te llegan bien.',
                        $this->enumerate($mine),
                    ),
                );
                ++$written;
            }
        }

        if ($written > 0) {
            $this->inbox->flush();
        }

        return $written;
    }

    /**
     * Avisa a quien coordina socixs de cuántas fichas están a medias.
     *
     * @param int $incomplete cuántas fichas están a medias
     *
     * @return int cuántos avisos se han dejado
     */
    private function notifyCoordinators(int $incomplete): int
    {
        if (0 === $incomplete) {
            return 0;
        }

        $written = 0;
        foreach ($this->coordinators() as $user) {
            if ($this->notifications->hasUnreadOfKind($user, Notification::KIND_PARTNERS_INCOMPLETE)) {
                continue;
            }

            $this->inbox->record(
                $user,
                Notification::KIND_PARTNERS_INCOMPLETE,
                1 === $incomplete
                    ? 'Una ficha de socix con datos sin rellenar'
                    : sprintf('%d fichas de socix con datos sin rellenar', $incomplete),
                'Entra a verlas: para cada una se dice qué le falta y quién puede rellenarlo.',
            );
            ++$written;
        }

        if ($written > 0) {
            $this->inbox->flush();
        }

        return $written;
    }

    /**
     * Las cuentas que coordinan socixs, resolviendo la jerarquía de roles.
     *
     * NO VALE BUSCAR EL ROL LITERAL en la columna: quien coordina suele tener
     * ROLE_ADMIN, y de ahí a ROLE_GESTION_SOCIXS hay dos saltos
     * (ROLE_ADMIN → ROLE_GESTION_SOCIXS_EDIT → ROLE_GESTION_SOCIXS). Con un LIKE
     * el aviso no le llegaría a nadie, y el fallo sería invisible: una tarea que
     * corre en verde sin avisar a nadie.
     *
     * @return list<User> las cuentas que coordinan socixs
     */
    private function coordinators(): array
    {
        $coordinators = [];

        foreach ($this->users->findEnabled() as $user) {
            $reachable = $this->roleHierarchy->getReachableRoleNames($user->getRoles());
            if (\in_array(self::COORDINATOR_ROLE, $reachable, true)) {
                $coordinators[] = $user;
            }
        }

        return $coordinators;
    }

    /**
     * Las cuentas de acceso de cada socix, indexadas por id de socix.
     *
     * En una consulta y no una por socix: es lo que más filas toca de esta tarea.
     *
     * @param list<Partner> $partners lxs socixs
     *
     * @return array<int, list<User>> cuentas por id de socix
     */
    private function usersByPartner(array $partners): array
    {
        if ([] === $partners) {
            return [];
        }

        $byPartner = [];
        foreach ($this->users->findByPartners($partners) as $user) {
            $partnerId = $user->getPartner()?->getId();
            if (null !== $partnerId) {
                $byPartner[$partnerId][] = $user;
            }
        }

        return $byPartner;
    }

    /**
     * "el DNI", "el DNI y la dirección", "el DNI, la dirección y el teléfono".
     *
     * Se enumera en castellano y no con una lista de guiones porque el aviso se
     * lee de un tirón en una línea; una lista de un solo elemento con su guión
     * delante se lee como un error de la pantalla.
     *
     * @param list<string> $labels las etiquetas de lo que falta
     *
     * @return string la enumeración
     */
    private function enumerate(array $labels): string
    {
        if (1 === \count($labels)) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' y ' . $last;
    }
}
