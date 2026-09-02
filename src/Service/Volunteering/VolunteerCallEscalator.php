<?php

namespace App\Service\Volunteering;

use App\Entity\VolunteerCall;
use App\Entity\VolunteerShift;
use App\Repository\VolunteerCallRepository;
use App\Service\AppSettings;

/**
 * Decide si a un turno le toca AHORA abrir su aviso a más gente, y a qué
 * alcance. Es la mitad "cuándo" del envío: el "quiénes" lo resuelve
 * {@see VolunteerAudienceResolver} y el "por dónde", el notificador.
 *
 * VA POR TURNO, no por tarea: lo que se pide es gente para el sábado, y la
 * escalada del sábado no puede gastar la del domingo.
 *
 * POR QUÉ EXISTE ESTE ESCALADO. El permiso de notificaciones del navegador se
 * pierde una sola vez y para siempre: quien lo deniega o lo apaga no vuelve a
 * recibir nada, porque `requestPermission()` ya ni llega a enseñar el diálogo.
 * Mandar a 246 socixs que hacen falta dos personas molesta a 244, y a la tercera
 * vez media asociación ha apagado el canal para el día que de verdad importe.
 * Así que el aviso se abre por pasos y sólo mientras siga faltando gente.
 *
 * NUNCA LLEGA SOLO A "TODO EL MUNDO" ({@see VolunteerCall::AUTOMATIC_SCOPES}).
 * Ese alcance existe, pero lo lanza una persona desde la pantalla de gestión,
 * que es quien puede juzgar que la cosa es lo bastante seria como para gastar
 * el canal. Un automatismo no sabe distinguir "falta gente para la plantación
 * de primavera" de "falta gente y si no vienen se pierde la cosecha".
 *
 * Esta clase NO manda nada ni escribe nada: sólo responde. Quien envía es quien
 * persiste el {@see VolunteerCall}, y la unicidad (shift, scope) de la BBDD es
 * la que garantiza de verdad que un reintento del planificador no duplique el
 * aviso — aquí sólo se decide, y decidir dos veces no hace daño.
 */
class VolunteerCallEscalator
{
    public function __construct(
        private readonly VolunteerCallRepository $calls,
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * El alcance que toca abrir ahora mismo para este turno, o null si no toca
     * ninguno (ya está cubierto, ya se avisó a todo lo que el automatismo puede
     * abrir, o aún no ha pasado el margen de espera).
     *
     * @param VolunteerShift          $shift el turno
     * @param \DateTimeImmutable|null $now   momento de referencia; por defecto, ahora
     *
     * @return string|null uno de VolunteerCall::SCOPE_*, o null si no toca avisar
     */
    public function nextScope(VolunteerShift $shift, ?\DateTimeImmutable $now = null): ?string
    {
        $now ??= new \DateTimeImmutable();

        // Un turno anulado, pasado o ya lleno, o de una tarea que no está
        // publicada, no pide gente. Toda esa regla vive en la entidad, no aquí.
        if (!$shift->isOpen($now)) {
            return null;
        }

        $offer = $shift->getOffer();
        if (null === $offer) {
            return null;
        }

        $sent = $this->calls->sentScopes($shift);

        // El aviso general, aunque se haya mandado a mano, cierra la escalada:
        // si ya lo ha visto todo el mundo, no queda nadie a quien ampliarlo.
        if (\in_array(VolunteerCall::SCOPE_EVERYONE, $sent, true)) {
            return null;
        }

        foreach (VolunteerCall::AUTOMATIC_SCOPES as $scope) {
            if (\in_array($scope, $sent, true)) {
                continue;
            }

            // Una tarea que no es para cualquiera se queda en el primer paso.
            // Proponerlo igual y dejar que el resolver devuelva cero
            // destinatarios registraría una llamada vacía que gastaría el
            // UNIQUE (shift, scope) y mataría la escalada en silencio.
            if (VolunteerCall::SCOPE_UNSPECIFIED === $scope && !$offer->isOpenToAnyone()) {
                continue;
            }

            // Y una tarea SIN categorías no tiene primer paso: nadie puede haber
            // marcado "avísame de esto" si no hay ningún "esto". Sin este salto,
            // una tarea sin categorías marcada como apta para cualquiera se
            // quedaba encallada para siempre: el paso 1 no encontraba a nadie,
            // luego no se registraba, luego el paso 2 nunca llegaba a
            // proponerse — y no avisaba a nadie jamás.
            if (VolunteerCall::SCOPE_MATCHING === $scope && $offer->getCategories()->isEmpty()) {
                continue;
            }

            // El primer paso sale en cuanto la tarea está publicada; los
            // siguientes esperan. Sin esa espera, los dos pasos saldrían en el
            // mismo tick del planificador y el escalado no habría escalado nada.
            if ([] !== $sent && !$this->waitedLongEnough($shift, $now)) {
                return null;
            }

            return $scope;
        }

        return null;
    }

    /**
     * Si ha pasado ya el margen de espera desde el último aviso de este turno.
     *
     * @param VolunteerShift     $shift el turno
     * @param \DateTimeImmutable $now   momento de referencia
     *
     * @return bool true si se puede ampliar el aviso
     */
    private function waitedLongEnough(VolunteerShift $shift, \DateTimeImmutable $now): bool
    {
        $last = $this->calls->findLast($shift);
        if (null === $last) {
            return true;
        }

        $hours = $this->settings->getInt(AppSettings::VOLUNTEERING_ESCALATION_HOURS);
        $deadline = \DateTimeImmutable::createFromInterface($last->getSentAt())
            ->modify(sprintf('+%d hours', $hours));

        return $now >= $deadline;
    }
}
