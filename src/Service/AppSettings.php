<?php

namespace App\Service;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Acceso tipado a los ajustes de configuración de la app ({@see Setting}).
 *
 * Aquí viven los catálogos de claves conocidas — {@see self::BOOLEANS} y
 * {@see self::INTEGERS} —: cada ajuste se declara con su etiqueta, su texto de
 * ayuda (lo que ve la administración en la pantalla /gestion/settings) y
 * su default (los enteros, además, con su rango min/max). Leer una clave fuera
 * del catálogo es un bug de programación y revienta en el acto, no devuelve un
 * default silencioso.
 *
 * Los valores se cargan en una sola query y se memoizan por request.
 */
class AppSettings
{
    /**
     * ¿Pueden crearse cuenta lxs socixs que aún no tienen User? Afecta a las
     * dos vías de autoprovisioning: el SSO de Google y el primer acceso del
     * magic-link. Con esto apagado, las cuentas YA creadas siguen entrando, y
     * la administración puede dar acceso individual desde la ficha del socix.
     */
    public const SELF_REGISTRATION = 'access.self_registration';

    /**
     * Interruptor general del correo saliente. Apagado, la app NO entrega NINGÚN
     * email, sea cual sea su toggle individual: lo corta {@see \App\Mailer\KillSwitchMailer}
     * antes de llegar al transporte. Pensado como apagado de emergencia y para
     * probar flujos que envían correo sin que salga nada. Encendido (default),
     * cada email sigue gobernado por su propio ajuste.
     */
    public const EMAIL_ENABLED = 'email.enabled';

    /** Envío del recordatorio de recogida (app:send-pickup-reminders). */
    public const EMAIL_PICKUP_REMINDER = 'email.pickup_reminder';

    /**
     * ¿Se incluyen enlaces de acción (botón "Ver mi calendario") en el
     * recordatorio? Aun encendido, sólo se pintan para socixs que ya pueden
     * entrar a la web ({@see PartnerAccessPolicy::canUseActionLinks()}); apagado,
     * el email es puramente informativo. Pensado para no ofrecer enlaces
     * mientras el acceso de socixs no esté listo en producción.
     */
    public const EMAIL_PICKUP_REMINDER_LINKS = 'email.pickup_reminder_links';

    /** Envío del resumen de cambios a admin (app:send-admin-delivery-changes-summary). */
    public const EMAIL_ADMIN_DELIVERY_SUMMARY = 'email.admin_delivery_summary';

    /** Envío del recordatorio de llegadas/salidas del albergue al equipo (app:send-albergue-arrivals-reminder). */
    public const EMAIL_ALBERGUE_REMINDER = 'email.albergue_reminder';

    /**
     * Red de seguridad para entornos de prueba: si tiene valor, TODOS los emails
     * que envía la app se entregan SOLO a esa(s) dirección(es) — separadas por
     * comas —, sin importar el destinatario original (que sigue visible en la
     * cabecera To). La aplica {@see \App\Mailer\RedirectRecipientsListener}.
     * Vacío (default) = sin redirección, cada email va a su destinatario real:
     * así DEBE quedar en producción. Se edita desde la pantalla de diagnóstico
     * de envíos, no desde el form general de ajustes.
     */
    public const EMAIL_REDIRECT_TO = 'email.redirect_to';

    /**
     * Dirección de Reply-To que se añade a TODOS los emails salientes que no
     * lleven ya uno propio (p.ej. el formulario de contacto pone el del
     * visitante y NO se pisa). El From es un buzón `noreply@`; este ajuste
     * permite que las respuestas de socixs lleguen a una cuenta humana leída
     * durante el rodaje, cuando aún no tienen acceso a la web. La aplica
     * {@see \App\Mailer\ReplyToListener}. Vacío (default) = sin Reply-To. Se
     * edita desde la pantalla de diagnóstico de envíos.
     */
    public const EMAIL_REPLY_TO = 'email.reply_to';

    /**
     * Antelación (en días sobre la fecha del reparto) con la que se envía el
     * recordatorio de recogida. La lee {@see \App\Command\SendPickupReminderCommand}.
     */
    public const PICKUP_REMINDER_DAYS_BEFORE = 'email.pickup_reminder_days_before';

    /**
     * Aforo FÍSICO de referencia del albergue: número de camas disponibles en la
     * casa. NO es el límite que aplica el guard de ocupación —ese es el aforo
     * OPERATIVO por mes ({@see \App\Entity\HostingCapacity})—, sino el valor que
     * se usa como aforo por defecto de un mes que aún no tiene fila configurada.
     * Lo lee {@see \App\Service\Hosting\HostingCapacityResolver}.
     */
    public const HOSTING_PHYSICAL_CAPACITY = 'hosting.physical_capacity';

    /**
     * Antelación (en días sobre la fecha física del reparto) con la que se
     * cierra el plazo del cambio puntual de un socix. La lee {@see \App\Service\Delivery\Rule\DeadlineRule}.
     */
    public const DEADLINE_DAYS_BEFORE = 'delivery.deadline_days_before';

    /**
     * Hora exacta del día de cierre del plazo de cambios, en formato "HH:MM"
     * (24h). La lee {@see \App\Service\Delivery\Rule\DeadlineRule}.
     */
    public const DEADLINE_TIME = 'delivery.deadline_time';

    /**
     * Umbral de la regla de equilibrio: máxima diferencia de cestas permitida
     * entre dos viernes consecutivos tras aplicar un cambio puntual. La lee
     * {@see \App\Service\Delivery\Rule\BalanceWithinThresholdRule}. Sólo entra
     * en juego cuando lxs socixs piden cambios desde el panel (autoservicio);
     * admin siempre puede forzar el cambio aunque rompa el equilibrio.
     */
    public const BALANCE_THRESHOLD = 'delivery.balance_threshold';

    /**
     * ¿Pueden lxs socixs entrar a la web? Gobierna las tres vías de acceso no-admin
     * (formulario, magic-link y Google) desde un único punto, {@see \App\Security\UserChecker}.
     * Apagado, sólo entra quien tenga un rol de gestión/admin; lxs socixs reciben
     * un aviso de que el acceso aún no está abierto. Pensado para el rodaje en
     * producción: primero se abre el acceso (sólo lectura) y, más tarde, el
     * autoservicio ({@see self::FEATURE_PARTNER_SELFSERVICE}).
     */
    public const FEATURE_PARTNER_LOGIN = 'feature.partner_login';

    /**
     * ¿Pueden lxs socixs hacer cambios desde su panel (saltar cesta, mover,
     * cambiar de viernes o de nodo)? Apagado, el panel y el calendario quedan en
     * solo-lectura y las acciones de escritura responden 403. Lo resuelve
     * {@see \App\Security\FeatureVoter} vía {@see is_granted('FEATURE_PARTNER_SELFSERVICE')}.
     */
    public const FEATURE_PARTNER_SELFSERVICE = 'feature.partner_selfservice';

    /**
     * ¿Está abierto el módulo de encuestas (gestión y respuesta de socixs)?
     * Apagado, se ocultan del menú y sus rutas responden 403. Lo resuelve
     * {@see \App\Security\FeatureVoter} vía {@see is_granted('FEATURE_SURVEYS')}.
     */
    public const FEATURE_SURVEYS = 'feature.surveys';

    /**
     * ¿Está abierto el módulo laboral (registro de jornada y vacaciones de los
     * trabajadores)? Apagado, se oculta del menú y sus rutas (/gestion/staff y
     * /work) responden 403. Lo resuelve {@see \App\Security\FeatureVoter} vía
     * {@see is_granted('FEATURE_LABORAL')}.
     */
    public const FEATURE_LABORAL = 'feature.laboral';

    /**
     * Interruptores de las tareas programadas (crons). Apagado, el comando
     * correspondiente sale sin hacer nada en cuanto arranca: como el hosting es
     * solo-FTP y no podemos tocar el crontab desde la app, el cron sigue
     * disparando pero se auto-inhibe leyendo este flag. Son independientes de los
     * toggles de email: para los dos crons que envían correo, apagar el cron
     * impide incluso calcular destinatarios; apagar solo el email deja correr la
     * tarea pero no entrega nada.
     */
    public const CRON_PICKUP_REMINDER = 'cron.pickup_reminder';
    public const CRON_ADMIN_DELIVERY_SUMMARY = 'cron.admin_delivery_summary';
    public const CRON_PURGE_USAGE_HITS = 'cron.purge_usage_hits';
    public const CRON_GENERATE_WEEKLY_DELIVERY = 'cron.generate_weekly_delivery';
    public const CRON_ALBERGUE_REMINDER = 'cron.albergue_reminder';

    /**
     * Mapa de tareas programadas para la ejecución manual desde la pantalla de
     * configuración: clave del toggle => metadatos. `command` es el nombre del
     * comando de consola que se lanza en proceso ({@see \App\Controller\SettingsController::runCron});
     * `confirm` marca los que envían correo real (piden confirmación en la UI);
     * `dry` los que ofrecen además un botón de previsualización (--dry-run).
     * Es también la lista blanca: sólo se puede lanzar a mano lo declarado aquí.
     */
    public const CRONS = [
        self::CRON_GENERATE_WEEKLY_DELIVERY => ['command' => 'app:generate-weekly-delivery', 'confirm' => false, 'dry' => false],
        self::CRON_PICKUP_REMINDER => ['command' => 'app:send-pickup-reminders', 'confirm' => true, 'dry' => true],
        self::CRON_ADMIN_DELIVERY_SUMMARY => ['command' => 'app:send-admin-delivery-changes-summary', 'confirm' => true, 'dry' => true],
        self::CRON_PURGE_USAGE_HITS => ['command' => 'app:purge-usage-hits', 'confirm' => false, 'dry' => false],
        self::CRON_ALBERGUE_REMINDER => ['command' => 'app:send-albergue-arrivals-reminder', 'confirm' => true, 'dry' => true],
    ];

    /**
     * Catálogo de ajustes booleanos: clave => [grupo, etiqueta, ayuda, default].
     * La pantalla de configuración se construye desde aquí; añadir un ajuste
     * nuevo es añadir una entrada (y leerla donde toque).
     */
    public const BOOLEANS = [
        self::EMAIL_ENABLED => [
            'group' => 'Envío de emails',
            'label' => 'Enviar emails',
            'help' => 'Interruptor general. Apagado, la app no envía NINGÚN email (recordatorios, enlaces de acceso, resúmenes…), pase lo que pase con los ajustes de abajo. Úsalo como apagado de emergencia o para probar sin que salga nada. En funcionamiento normal, déjalo encendido.',
            'default' => true,
        ],
        self::SELF_REGISTRATION => [
            'group' => 'Acceso de socixs',
            'label' => 'Alta abierta de usuarixs nuevxs',
            'help' => 'Si está apagado, quien no tenga cuenta no puede creársela (ni con Google ni con el primer acceso por email). Las cuentas ya creadas siguen entrando, y se puede dar acceso a alguien concreto desde su ficha.',
            'default' => false,
        ],
        self::EMAIL_PICKUP_REMINDER => [
            'group' => 'Emails a socixs',
            'label' => 'Recordatorio de recogida',
            'help' => 'Email a quincenales y mensuales a los que les toca cesta el próximo viernes.',
            'default' => false,
        ],
        self::EMAIL_PICKUP_REMINDER_LINKS => [
            'group' => 'Emails a socixs',
            'label' => 'Incluir enlaces de acción en el recordatorio',
            'help' => 'Añade el botón “Ver mi calendario” al email, sólo para socixs que ya pueden entrar a la web. Mantenlo apagado mientras el acceso de socixs no esté abierto.',
            'default' => false,
        ],
        self::EMAIL_ADMIN_DELIVERY_SUMMARY => [
            'group' => 'Emails internos',
            'label' => 'Resumen de cambios a administración',
            'help' => 'Digest periódico con los cambios autoservicio de lxs socixs (saltar cesta, cambiar de nodo…).',
            'default' => true,
        ],
        self::EMAIL_ALBERGUE_REMINDER => [
            'group' => 'Emails internos',
            'label' => 'Recordatorio de llegadas/salidas del albergue',
            'help' => 'Aviso al equipo con las llegadas y salidas confirmadas de los próximos días en el albergue (preparar camas, etc.). Se envía a la dirección configurada en el cron.',
            'default' => false,
        ],
        self::FEATURE_PARTNER_LOGIN => [
            'group' => 'Funcionalidades en rodaje',
            'label' => 'Acceso de socixs a la web',
            'help' => 'Permite que lxs socixs entren (formulario, enlace por email o Google). Apagado, sólo entra el equipo de gestión. Enciéndelo cuando quieras que empiecen a entrar a consultar sus datos.',
            'default' => false,
        ],
        self::FEATURE_PARTNER_SELFSERVICE => [
            'group' => 'Funcionalidades en rodaje',
            'label' => 'Autoservicio de socixs',
            'help' => 'Permite que lxs socixs hagan cambios desde su panel (saltar cesta, mover, cambiar de viernes o de nodo). Apagado, su panel queda en solo-lectura. Requiere tener abierto el acceso de socixs.',
            'default' => false,
        ],
        self::FEATURE_SURVEYS => [
            'group' => 'Funcionalidades en rodaje',
            'label' => 'Encuestas',
            'help' => 'Abre el módulo de encuestas, tanto la gestión interna como la respuesta de lxs socixs. Apagado, se oculta del menú y no es accesible.',
            'default' => false,
        ],
        self::FEATURE_LABORAL => [
            'group' => 'Funcionalidades en rodaje',
            'label' => 'Control horario y vacaciones',
            'help' => 'Abre el módulo laboral: el fichaje de los trabajadores, su calendario y vacaciones, y la gestión del personal (incluidos festivos). Apagado, se oculta del menú y no es accesible.',
            'default' => false,
        ],
        self::CRON_GENERATE_WEEKLY_DELIVERY => [
            'group' => 'Tareas programadas',
            'label' => 'Congelar el listado semanal',
            'help' => 'Cada lunes blinda el listado del reparto de la semana que entra, para que no se mueva bajo quien reparte (app:generate-weekly-delivery). Es la tarea más delicada: apagada, el listado de la semana NO se congela. Déjala encendida salvo que sepas lo que haces.',
            'default' => true,
        ],
        self::CRON_PICKUP_REMINDER => [
            'group' => 'Tareas programadas',
            'label' => 'Tarea del recordatorio de recogida',
            'help' => 'Ejecuta a diario el comando que avisa a quincenales y mensuales del próximo reparto (app:send-pickup-reminders). Es independiente del email: apagada aquí, la tarea ni se ejecuta; si la dejas encendida pero apagas el email del recordatorio, corre pero no envía.',
            'default' => true,
        ],
        self::CRON_ADMIN_DELIVERY_SUMMARY => [
            'group' => 'Tareas programadas',
            'label' => 'Tarea del resumen a administración',
            'help' => 'Ejecuta el comando del digest periódico de cambios a administración (app:send-admin-delivery-changes-summary). Independiente del email del resumen, igual que el recordatorio.',
            'default' => true,
        ],
        self::CRON_PURGE_USAGE_HITS => [
            'group' => 'Tareas programadas',
            'label' => 'Purga del rastro de uso',
            'help' => 'Borra periódicamente la telemetría de uso anterior al período de retención (app:purge-usage-hits), por minimización de datos. Apagada, el rastro se acumula sin límite.',
            'default' => true,
        ],
        self::CRON_ALBERGUE_REMINDER => [
            'group' => 'Tareas programadas',
            'label' => 'Recordatorio de llegadas/salidas del albergue',
            'help' => 'Ejecuta a diario el comando que avisa al equipo de las llegadas y salidas próximas del albergue (app:send-albergue-arrivals-reminder). Independiente del email: apagada aquí, la tarea ni se ejecuta; encendida pero con el email apagado, corre pero no envía.',
            'default' => true,
        ],
    ];

    /**
     * Catálogo de ajustes enteros: clave => [grupo, etiqueta, ayuda, default,
     * min, max]. Mismo contrato que {@see self::BOOLEANS} pero con rango: al
     * guardar se recorta a [min, max] para que la pantalla no pueda meter un
     * valor absurdo.
     */
    public const INTEGERS = [
        // Cada entrada lleva además 'unit' (sufijo que se pinta junto al campo:
        // "días", "h"…), aparte de min/max.
        self::PICKUP_REMINDER_DAYS_BEFORE => [
            'group' => 'Emails a socixs',
            'label' => 'Antelación del recordatorio',
            'help' => 'Cuántos días antes del reparto se envía el recordatorio. 2 = se manda dos días antes (miércoles para un reparto del viernes). Requiere que el cron corra a diario.',
            'default' => 2,
            'min' => 0,
            'max' => 14,
            'unit' => 'días',
        ],
        self::DEADLINE_DAYS_BEFORE => [
            'group' => 'Cierre de reparto',
            'label' => 'Antelación del cierre',
            'help' => 'Cuántos días antes del reparto se cierra el plazo para que un socix pida un cambio puntual (saltar, cambiar de viernes o de nodo). 1 = el día anterior al reparto.',
            'default' => 1,
            'min' => 0,
            'max' => 7,
            'unit' => 'días',
        ],
        self::BALANCE_THRESHOLD => [
            'group' => 'Cierre de reparto',
            'label' => 'Umbral de equilibrio entre viernes',
            'help' => 'Máxima diferencia de cestas permitida entre dos viernes consecutivos cuando un socix pide un cambio puntual desde el panel. 3 = no se permite que un viernes acabe con más de 3 cestas de diferencia respecto al anterior o al siguiente. La administración siempre puede forzar el cambio aunque rompa el equilibrio.',
            'default' => 3,
            'min' => 1,
            'max' => 20,
            'unit' => 'cestas',
        ],
        self::HOSTING_PHYSICAL_CAPACITY => [
            'group' => 'Albergue',
            'label' => 'Aforo físico del albergue',
            'help' => 'Número de camas de la casa. Se usa como aforo por defecto de los meses que aún no tengas configurados a mano. Para abrir o cerrar meses concretos o cambiar el aforo de un mes, usa el calendario de aforo del albergue.',
            'default' => 0,
            'min' => 0,
            'max' => 50,
            'unit' => 'camas',
        ],
    ];

    /**
     * Catálogo de ajustes de hora ("HH:MM", 24h): clave => [grupo, etiqueta,
     * ayuda, default]. Se editan con un selector de hora nativo y se validan
     * con {@see self::TIME_PATTERN} al guardar.
     */
    public const TIMES = [
        self::DEADLINE_TIME => [
            'group' => 'Cierre de reparto',
            'label' => 'Hora del cierre',
            'help' => 'A qué hora del día de cierre termina el plazo para pedir un cambio puntual. La administración siempre puede forzar un cambio fuera de plazo.',
            'default' => '23:59',
        ],
    ];

    /** Valida una hora "HH:MM" en 24h (00:00–23:59). */
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * Catálogo de ajustes de texto libre: clave => [grupo, etiqueta, ayuda,
     * default]. A diferencia de {@see self::BOOLEANS}/{@see self::INTEGERS}/
     * {@see self::TIMES}, estos NO se pintan en el form general de ajustes: son
     * ajustes que viven en pantallas específicas (p.ej. la redirección de
     * pruebas, en el diagnóstico de envíos).
     */
    public const STRINGS = [
        self::EMAIL_REDIRECT_TO => [
            'group' => 'Pruebas de envío',
            'label' => 'Redirigir todos los emails a',
            'help' => 'Direcciones (separadas por comas) que recibirán TODOS los emails de la app, en lugar de sus destinatarios reales. Pensado para staging: te llegan a tu bandeja sin escribir a socixs. DÉJALO VACÍO EN PRODUCCIÓN.',
            'default' => '',
        ],
        self::EMAIL_REPLY_TO => [
            'group' => 'Correo',
            'label' => 'Responder-a (Reply-To) de los emails',
            'help' => 'Si rellenas una dirección, las respuestas a los correos de la app irán ahí (el remitente sigue siendo noreply@). Útil en el rodaje, mientras lxs socixs aún no gestionan desde la web. Vacío = sin Reply-To.',
            'default' => '',
        ],
    ];

    /**
     * Overrides cargados de BBDD, clave => valor crudo. Null hasta la primera
     * lectura (memo por request).
     *
     * @var array<string, string|null>|null
     */
    private ?array $stored = null;

    public function __construct(
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Lee un ajuste booleano del catálogo: el override de BBDD si existe, el
     * default si no.
     *
     * @param string $name Clave declarada en {@see self::BOOLEANS}.
     * @throws \InvalidArgumentException Si la clave no está en el catálogo.
     */
    public function getBool(string $name): bool
    {
        $definition = self::BOOLEANS[$name]
            ?? throw new \InvalidArgumentException(sprintf('Ajuste desconocido "%s"; decláralo en AppSettings::BOOLEANS.', $name));

        $stored = $this->loadStored()[$name] ?? null;

        return $stored === null ? $definition['default'] : $stored === '1';
    }

    /**
     * Persiste un ajuste booleano (crea la fila si no existía) y refresca la
     * memo para que la misma request lea el valor nuevo.
     *
     * @param string $name  Clave declarada en {@see self::BOOLEANS}.
     * @param bool   $value Valor a guardar.
     * @throws \InvalidArgumentException Si la clave no está en el catálogo.
     */
    public function setBool(string $name, bool $value): void
    {
        if (!isset(self::BOOLEANS[$name])) {
            throw new \InvalidArgumentException(sprintf('Ajuste desconocido "%s"; decláralo en AppSettings::BOOLEANS.', $name));
        }

        $setting = $this->repository->findOneBy(['name' => $name]) ?? (new Setting())->setName($name);
        $setting->setValue($value ? '1' : '0');

        $this->em->persist($setting);
        $this->em->flush();

        $this->stored = null;
    }

    /**
     * Lee un ajuste entero del catálogo: el override de BBDD si existe, el
     * default si no.
     *
     * @param string $name Clave declarada en {@see self::INTEGERS}.
     * @throws \InvalidArgumentException Si la clave no está en el catálogo.
     */
    public function getInt(string $name): int
    {
        $definition = self::INTEGERS[$name]
            ?? throw new \InvalidArgumentException(sprintf('Ajuste desconocido "%s"; decláralo en AppSettings::INTEGERS.', $name));

        $stored = $this->loadStored()[$name] ?? null;

        return $stored === null ? $definition['default'] : (int) $stored;
    }

    /**
     * Persiste un ajuste entero (crea la fila si no existía), recortándolo al
     * rango [min, max] del catálogo, y refresca la memo.
     *
     * @param string $name  Clave declarada en {@see self::INTEGERS}.
     * @param int    $value Valor a guardar (se recorta al rango permitido).
     * @throws \InvalidArgumentException Si la clave no está en el catálogo.
     */
    public function setInt(string $name, int $value): void
    {
        $definition = self::INTEGERS[$name]
            ?? throw new \InvalidArgumentException(sprintf('Ajuste desconocido "%s"; decláralo en AppSettings::INTEGERS.', $name));

        $value = max($definition['min'], min($definition['max'], $value));

        $setting = $this->repository->findOneBy(['name' => $name]) ?? (new Setting())->setName($name);
        $setting->setValue((string) $value);

        $this->em->persist($setting);
        $this->em->flush();

        $this->stored = null;
    }

    /**
     * Lee un ajuste de texto del catálogo: el override de BBDD si existe, el
     * default si no.
     *
     * @param string $name Clave declarada en {@see self::STRINGS}.
     * @throws \InvalidArgumentException Si la clave no está en el catálogo.
     */
    public function getString(string $name): string
    {
        $definition = self::STRINGS[$name]
            ?? throw new \InvalidArgumentException(sprintf('Ajuste desconocido "%s"; decláralo en AppSettings::STRINGS.', $name));

        $stored = $this->loadStored()[$name] ?? null;

        return $stored ?? $definition['default'];
    }

    /**
     * Persiste un ajuste de texto (crea la fila si no existía), recortando
     * espacios sobrantes, y refresca la memo.
     *
     * @param string $name  Clave declarada en {@see self::STRINGS}.
     * @param string $value Valor a guardar.
     * @throws \InvalidArgumentException Si la clave no está en el catálogo.
     */
    public function setString(string $name, string $value): void
    {
        if (!isset(self::STRINGS[$name])) {
            throw new \InvalidArgumentException(sprintf('Ajuste desconocido "%s"; decláralo en AppSettings::STRINGS.', $name));
        }

        $setting = $this->repository->findOneBy(['name' => $name]) ?? (new Setting())->setName($name);
        $setting->setValue(trim($value));

        $this->em->persist($setting);
        $this->em->flush();

        $this->stored = null;
    }

    /**
     * Lee un ajuste de hora del catálogo en formato "HH:MM": el override de
     * BBDD si existe y es válido, el default si no.
     *
     * @param string $name Clave declarada en {@see self::TIMES}.
     * @throws \InvalidArgumentException Si la clave no está en el catálogo.
     */
    public function getTime(string $name): string
    {
        $definition = self::TIMES[$name]
            ?? throw new \InvalidArgumentException(sprintf('Ajuste desconocido "%s"; decláralo en AppSettings::TIMES.', $name));

        $stored = $this->loadStored()[$name] ?? null;

        return ($stored !== null && preg_match(self::TIME_PATTERN, $stored)) ? $stored : $definition['default'];
    }

    /**
     * Persiste un ajuste de hora "HH:MM" (crea la fila si no existía). Un valor
     * con formato inválido se ignora y se cae al default del catálogo, para que
     * la pantalla nunca deje una hora corrupta en BBDD.
     *
     * @param string $name  Clave declarada en {@see self::TIMES}.
     * @param string $value Hora a guardar ("HH:MM").
     * @throws \InvalidArgumentException Si la clave no está en el catálogo.
     */
    public function setTime(string $name, string $value): void
    {
        $definition = self::TIMES[$name]
            ?? throw new \InvalidArgumentException(sprintf('Ajuste desconocido "%s"; decláralo en AppSettings::TIMES.', $name));

        if (!preg_match(self::TIME_PATTERN, $value)) {
            $value = $definition['default'];
        }

        $setting = $this->repository->findOneBy(['name' => $name]) ?? (new Setting())->setName($name);
        $setting->setValue($value);

        $this->em->persist($setting);
        $this->em->flush();

        $this->stored = null;
    }

    /**
     * Carga todos los overrides en una query y los memoiza.
     *
     * @return array<string, string|null> clave => valor crudo.
     */
    private function loadStored(): array
    {
        if ($this->stored === null) {
            $this->stored = [];
            foreach ($this->repository->findAll() as $setting) {
                $this->stored[$setting->getName()] = $setting->getValue();
            }
        }

        return $this->stored;
    }
}
