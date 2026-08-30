<?php

namespace App\Service\Notification;

use App\Service\AppSettings;

/**
 * De qué avisa la asociación y por qué canales, declarado en un solo sitio.
 *
 * ES EL CATÁLOGO, no una preferencia: aquí se dice qué temas EXISTEN; lo que
 * cada socix quiere recibir lo guarda {@see NotificationPreferences}. Añadir un
 * tema —grupo de consumo, eventos— es añadir una entrada aquí, y aparece solo
 * en la pantalla de avisos y en las comprobaciones del envío.
 *
 * CADA TEMA DECLARA SU FEATURE. Un tema cuyo módulo está apagado no se pinta ni
 * se pregunta: ofrecer «avísame de voluntariado» donde no hay voluntariado es
 * prometer algo que no va a pasar. Los que no dependen de ninguna (la cesta) van
 * con `feature` a null, porque son el núcleo de ser socix y no se apagan.
 *
 * NO TODOS LOS TEMAS TIENEN LOS DOS CANALES. El voluntariado hoy sólo manda
 * push ({@see \App\Service\Volunteering\VolunteerCallNotifier} lo dice: «SÓLO
 * PUSH, DE MOMENTO»), así que ofrecer una casilla de correo ahí sería un
 * interruptor que no apaga nada. Se declara lo que de verdad se manda.
 */
final class NotificationTopic
{
    /** Aviso por correo electrónico. */
    public const CHANNEL_EMAIL = 'email';

    /** Aviso al móvil o al navegador (push). */
    public const CHANNEL_PUSH = 'push';

    /** Recogida de la cesta: cuándo y dónde toca. */
    public const PICKUP = 'pickup';

    /** Voluntariado: hace falta gente, y recordatorios de lo que te toca. */
    public const VOLUNTEERING = 'volunteering';

    /**
     * El catálogo. Clave => etiqueta, ayuda, feature que lo habilita (o null) y
     * canales por los que se manda de verdad.
     *
     * @var array<string, array{label: string, help: string, feature: ?string, channels: list<string>}>
     */
    public const TOPICS = [
        self::PICKUP => [
            'label' => 'Mi cesta',
            'help' => 'Cuándo y dónde te toca recoger. Se manda una vez por reparto, unos días antes.',
            'feature' => null,
            'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
        ],
        self::VOLUNTEERING => [
            'label' => 'Voluntariado',
            'help' => 'Cuando falta gente para algo que encaja contigo, y cuando se acerca una tarea a la que te has apuntado.',
            'feature' => AppSettings::FEATURE_VOLUNTEERING,
            // Sin correo: hoy el voluntariado sólo avisa por push.
            'channels' => [self::CHANNEL_PUSH],
        ],
    ];

    /**
     * Si un tema manda por un canal. Preguntar por un canal que ese tema no usa
     * no es un error —la pantalla lo hace para saber si pintar la casilla— y
     * responde que no.
     *
     * @param string $topic   clave del tema
     * @param string $channel uno de CHANNEL_*
     *
     * @return bool true si ese tema entrega por ese canal
     */
    public static function uses(string $topic, string $channel): bool
    {
        return \in_array($channel, self::TOPICS[$topic]['channels'] ?? [], true);
    }
}
