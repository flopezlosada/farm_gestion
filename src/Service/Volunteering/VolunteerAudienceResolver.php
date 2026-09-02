<?php

namespace App\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerShift;
use App\Repository\PartnerRepository;

/**
 * A quién se le pide gente para un turno, según el alcance del aviso. Es la
 * mitad "quiénes" del envío; el "cuándo" lo decide
 * {@see VolunteerCallEscalator} y el "por dónde", el notificador.
 *
 * Separado del envío a propósito: así se puede responder "¿a cuánta gente
 * llegaría esto?" antes de mandar nada —que es lo que necesita la pantalla de
 * gestión para que quien da al botón sepa a quién está molestando— y se puede
 * probar la política de audiencia sin tocar ni el correo ni el push.
 *
 * LAS PREFERENCIAS SON DE LA TAREA, LOS APUNTADOS DEL TURNO. El área ("avísame
 * de huerta") describe el tipo de trabajo, así que se mira en la tarea; quién ya
 * viene depende del día, así que se mira en el turno. Mezclarlo era el error
 * fácil: descontar a quien vino el martes dejaría sin aviso del sábado justo a
 * la gente que más colabora.
 *
 * QUIEN YA ESTÁ APUNTADO A ESE TURNO NO RECIBE NADA. Es obvio dicho así, y es
 * justo el tipo de cosa que se olvida cuando el filtro vive repartido por cada
 * sitio que manda un aviso: pedirle a alguien que venga a algo a lo que ya viene
 * es la forma más rápida de que apague las notificaciones.
 */
class VolunteerAudienceResolver
{
    public function __construct(private readonly PartnerRepository $partners)
    {
    }

    /**
     * Lxs socixs a quienes corresponde avisar de este turno con este alcance, ya
     * descontadxs quienes están apuntadxs a él.
     *
     * Un alcance que la tarea no admite devuelve lista vacía en vez de lanzar:
     * pedir la audiencia de algo que no toca es una pregunta legítima (la
     * pantalla la hace para pintar "0 personas") y no un error de programación.
     *
     * @param VolunteerShift $shift el turno por el que se pide gente
     * @param string         $scope uno de VolunteerCall::SCOPE_*
     *
     * @return list<Partner> lxs socixs a quienes avisar
     */
    public function resolve(VolunteerShift $shift, string $scope): array
    {
        $offer = $shift->getOffer();
        if (null === $offer) {
            return [];
        }

        $candidates = match ($scope) {
            VolunteerCall::SCOPE_MATCHING => $this->partners->findActiveMatchingVolunteerCategories(
                $offer->getCategories()->toArray()
            ),
            // El paso 2 sólo existe para tareas que cualquiera puede hacer.
            // Ampliarlo a una tarea que exige saber manejar una desbrozadora
            // sería mandar a gente a algo que no puede hacer.
            VolunteerCall::SCOPE_UNSPECIFIED => $offer->isOpenToAnyone()
                ? $this->partners->findActiveWithoutVolunteerPreferences()
                : [],
            VolunteerCall::SCOPE_EVERYONE => $this->partners->findAllActive(),
            default => [],
        };

        return $this->withoutSignedUp($candidates, $shift);
    }

    /**
     * Cuánta gente recibiría el aviso, sin llegar a mandarlo. Lo usa la pantalla
     * de gestión para que quien pulsa "avisar a todo el mundo" vea el número
     * antes de hacerlo.
     *
     * @param VolunteerShift $shift el turno
     * @param string         $scope uno de VolunteerCall::SCOPE_*
     *
     * @return int número de destinatarios
     */
    public function count(VolunteerShift $shift, string $scope): int
    {
        return \count($this->resolve($shift, $scope));
    }

    /**
     * Quita de la lista a quienes ya están apuntadxs a este turno y no se han
     * dado de baja.
     *
     * En PHP y no en SQL: son unos cientos de filas, el turno ya trae sus
     * inscripciones cargadas y meterlo en la consulta obligaría a repetir el
     * mismo NOT IN en las tres ramas del match.
     *
     * @param list<Partner>  $candidates lxs socixs candidatxs
     * @param VolunteerShift $shift      el turno
     *
     * @return list<Partner> lxs candidatxs que no están apuntadxs
     */
    private function withoutSignedUp(array $candidates, VolunteerShift $shift): array
    {
        $signedUp = [];
        foreach ($shift->getSignups() as $signup) {
            $partner = $signup->getPartner();
            // El id null se descarta en vez de indexarse: PHP convertiría esa
            // clave a "" y un solo socix sin persistir dejaría fuera del aviso a
            // todo el mundo que aún no tuviera id.
            if (!$signup->isCancelled() && null !== $partner && null !== $partner->getId()) {
                $signedUp[$partner->getId()] = true;
            }
        }

        if ([] === $signedUp) {
            return $candidates;
        }

        return array_values(array_filter(
            $candidates,
            static fn (Partner $partner): bool => !isset($signedUp[$partner->getId()])
        ));
    }
}
