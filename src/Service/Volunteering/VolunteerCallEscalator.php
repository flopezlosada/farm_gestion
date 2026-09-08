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
    /**
     * Cuántos días antes del turno tiene sentido ofrecer el aviso a toda la
     * asociación ({@see shouldOfferEveryone()}).
     *
     * Dos y no uno: un turno del sábado por la mañana con un solo día de margen
     * sólo podría difundirse el viernes, cuando quien tenía el fin de semana
     * libre ya lo ha ocupado. Y no más, o volvemos a ofrecerlo siempre.
     *
     * Constante y no ajuste: es una regla de cuándo se ENSEÑA un botón, no de
     * cuándo se manda nada, y un ajuste más en la pantalla de configuración
     * cuesta más de lo que resuelve. Si alguna vez estorba, es una línea.
     */
    private const EVERYONE_WINDOW_DAYS = 2;

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
     * A partir de cuándo se puede ampliar el aviso de este turno: la fecha del
     * último enviado más el margen de espera, o null si aún no se ha enviado
     * ninguno (entonces el primero puede salir ya).
     *
     * Es público porque la ficha del turno lo enseña: quien coordina no puede
     * lanzar los avisos automáticos, así que lo único útil que se le puede decir
     * es cuándo van a salir solos. Y vive aquí, y no en el controlador, para que
     * el margen se lea de un único sitio — si lo calculase también la pantalla,
     * el día que cambie el ajuste una de las dos mentiría.
     *
     * @param VolunteerShift $shift el turno
     *
     * @return \DateTimeImmutable|null el momento, o null si no hay espera que cumplir
     */
    public function escalationOpensAt(VolunteerShift $shift): ?\DateTimeImmutable
    {
        $last = $this->calls->findLast($shift);
        if (null === $last) {
            return null;
        }

        $hours = $this->settings->getInt(AppSettings::VOLUNTEERING_ESCALATION_HOURS);

        return \DateTimeImmutable::createFromInterface($last->getSentAt())
            ->modify(sprintf('+%d hours', $hours));
    }

    /**
     * Si toca ofrecer el aviso a TODA la asociación en la pantalla de gestión.
     *
     * No es lo mismo que poder mandarlo: se puede siempre. Es si merece la pena
     * enseñar el botón, y la respuesta es «sólo cuando ya no queda otra». Tres
     * condiciones a la vez:
     *
     *  1. El turno sigue pidiendo gente ({@see VolunteerShift::isOpen()} ya cubre
     *     anulado, pasado, lleno y tarea sin publicar).
     *  2. El automatismo ya ha hecho lo suyo: no queda ningún alcance automático
     *     por abrir. Ofrecerlo antes es saltarse el escalado a mano y gastar el
     *     canal de 246 personas pudiendo esperar al paso siguiente.
     *  3. Queda poco para el día. Un turno a dos semanas todavía se cubre solo.
     *
     * Enseñar este botón siempre —como se hacía— convertía en rutina el gesto
     * que más caro se paga: el permiso de notificaciones del navegador se pierde
     * una vez y para siempre.
     *
     * @param VolunteerShift          $shift el turno
     * @param \DateTimeImmutable|null $now   momento de referencia; por defecto, ahora
     *
     * @return bool true si la pantalla debe ofrecerlo
     */
    public function shouldOfferEveryone(VolunteerShift $shift, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        if (!$shift->isOpen($now)) {
            return false;
        }

        $sent = $this->calls->sentScopes($shift);
        if (\in_array(VolunteerCall::SCOPE_EVERYONE, $sent, true)) {
            return false;
        }

        // Que no quede ningún automático pendiente. Se pregunta a `nextScope()`
        // con el reloj adelantado hasta después de cualquier espera, porque lo
        // que importa aquí es si QUEDA alguno, no si toca ahora mismo: con el
        // reloj de verdad, un turno en pleno margen de espera diría «no queda
        // ninguno» y ofrecería el aviso general demasiado pronto.
        $opensAt = $this->escalationOpensAt($shift);
        $after = null !== $opensAt && $opensAt > $now ? $opensAt : $now;
        if (null !== $this->nextScope($shift, $after)) {
            return false;
        }

        $startsAt = $shift->getStartsAt();
        if (null === $startsAt) {
            return false;
        }

        return $startsAt <= $now->modify(sprintf('+%d days', self::EVERYONE_WINDOW_DAYS));
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
        $opensAt = $this->escalationOpensAt($shift);

        return null === $opensAt || $now >= $opensAt;
    }
}
