<?php

namespace App\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerOffer;
use App\Repository\PartnerRepository;

/**
 * A quién se le pide gente para una oferta, según el alcance del aviso. Es la
 * mitad "quiénes" del envío; el "cuándo" lo decide
 * {@see VolunteerCallEscalator} y el "por dónde", el notificador.
 *
 * Separado del envío a propósito: así se puede responder "¿a cuánta gente
 * llegaría esto?" antes de mandar nada —que es lo que necesita la pantalla de
 * gestión para que quien da al botón sepa a quién está molestando— y se puede
 * probar la política de audiencia sin tocar ni el correo ni el push.
 *
 * QUIEN YA ESTÁ APUNTADO NO RECIBE NADA. Es obvio dicho así, y es justo el tipo
 * de cosa que se olvida cuando el filtro vive repartido por cada sitio que
 * manda un aviso: pedirle a alguien que venga a algo a lo que ya viene es la
 * forma más rápida de que apague las notificaciones.
 */
class VolunteerAudienceResolver
{
    public function __construct(private readonly PartnerRepository $partners)
    {
    }

    /**
     * Lxs socixs a quienes corresponde avisar de esta oferta con este alcance,
     * ya descontadxs quienes están apuntadxs.
     *
     * Un alcance que la oferta no admite devuelve lista vacía en vez de lanzar:
     * pedir la audiencia de algo que no toca es una pregunta legítima (la
     * pantalla la hace para pintar "0 personas") y no un error de programación.
     *
     * @param VolunteerOffer $offer la oferta por la que se pide gente
     * @param string         $scope uno de VolunteerCall::SCOPE_*
     *
     * @return list<Partner> lxs socixs a quienes avisar
     */
    public function resolve(VolunteerOffer $offer, string $scope): array
    {
        $candidates = match ($scope) {
            VolunteerCall::SCOPE_MATCHING => $this->partners->findActiveMatchingVolunteerCategories(
                $offer->getCategories()->toArray()
            ),
            // El paso 2 sólo existe para ofertas que cualquiera puede hacer.
            // Ampliarlo a una tarea que exige saber manejar una desbrozadora
            // sería mandar a gente a algo que no puede hacer.
            VolunteerCall::SCOPE_UNSPECIFIED => $offer->isOpenToAnyone()
                ? $this->partners->findActiveWithoutVolunteerPreferences()
                : [],
            VolunteerCall::SCOPE_EVERYONE => $this->partners->findAllActive(),
            default => [],
        };

        return $this->withoutSignedUp($candidates, $offer);
    }

    /**
     * Cuánta gente recibiría el aviso, sin llegar a mandarlo. Lo usa la pantalla
     * de gestión para que quien pulsa "avisar a todo el mundo" vea el número
     * antes de hacerlo.
     *
     * @param VolunteerOffer $offer la oferta
     * @param string         $scope uno de VolunteerCall::SCOPE_*
     *
     * @return int número de destinatarios
     */
    public function count(VolunteerOffer $offer, string $scope): int
    {
        return \count($this->resolve($offer, $scope));
    }

    /**
     * Quita de la lista a quienes ya están apuntadxs y no se han dado de baja.
     *
     * En PHP y no en SQL: son unos cientos de filas, la oferta ya trae sus
     * inscripciones cargadas y meterlo en la consulta obligaría a repetir el
     * mismo NOT IN en las tres ramas del match.
     *
     * @param list<Partner>  $candidates lxs socixs candidatxs
     * @param VolunteerOffer $offer      la oferta
     *
     * @return list<Partner> lxs candidatxs que no están apuntadxs
     */
    private function withoutSignedUp(array $candidates, VolunteerOffer $offer): array
    {
        $signedUp = [];
        foreach ($offer->getSignups() as $signup) {
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
