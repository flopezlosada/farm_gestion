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

    /** Novedades: lo que se va añadiendo a la web. */
    public const NEWS = 'news';

    /** Grupo de consumo: se ha abierto un pedido colectivo al que apuntarse. */
    public const CONSUMER_GROUP = 'consumer_group';

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
            'help' => 'Cuando falta gente para algo que encaja contigo. El recordatorio de una tarea a la que ya te has apuntado llega siempre al móvil.',
            'feature' => AppSettings::FEATURE_VOLUNTEERING,
            // Los dos, pero OJO: el correo sólo sale de los avisos que piden
            // gente, y además exige que administración lo encienda
            // (AppSettings::EMAIL_VOLUNTEERING, apagado por defecto). El
            // recordatorio de «te toca mañana» sigue siendo sólo push: quien se
            // apuntó ya sabe que va, y ahí un correo más es ruido.
            'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
        ],
        self::NEWS => [
            'label' => 'Novedades de la web',
            'help' => 'Cuando añadimos algo nuevo que puedas usar. De vez en cuando, nunca urgente.',
            'feature' => null,
            // Los dos canales, cada uno con su casilla. Que el móvil se pueda
            // apagar POR SEPARADO es justo lo que permite mandarlo: sin esa
            // casilla, quien no quisiera enterarse de las novedades tendría que
            // apagar el push entero y se quedaría también sin el aviso de su
            // cesta, que es el que de verdad importa. Y el permiso del
            // navegador, una vez denegado, no se puede volver a pedir.
            'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
        ],
        self::CONSUMER_GROUP => [
            'label' => 'Grupo de consumo',
            'help' => 'Cuando se abre un pedido de aceite, fruta o legumbre al que puedes apuntarte. Son unos pocos al año.',
            'feature' => AppSettings::FEATURE_GRUPO_CONSUMO,
            // Encendido por defecto, como todos: el pedido está abierto a toda
            // la asociación, así que el aviso también. Quien no consuma nada de
            // esto lo apaga aquí y no vuelve a enterarse, sin tener que renunciar
            // al aviso de su cesta.
            'channels' => [self::CHANNEL_EMAIL, self::CHANNEL_PUSH],
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
