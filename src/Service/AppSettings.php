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

    /**
     * Envío por CORREO de los avisos de voluntariado que piden gente
     * (app:send-volunteer-calls). Independiente del push: el mismo aviso puede
     * salir sólo al móvil, sólo por correo o por los dos, y cada socix elige lo
     * suyo en su pantalla de avisos. Apagado por defecto porque el módulo nació
     * sólo con push y encenderlo empieza a mandar correos a gente que no los
     * esperaba.
     */
    public const EMAIL_VOLUNTEERING = 'email.volunteering';

    /** Envío del resumen de cambios a admin (app:send-admin-delivery-changes-summary). */
    public const EMAIL_ADMIN_DELIVERY_SUMMARY = 'email.admin_delivery_summary';

    /**
     * Destinatario(s) del resumen de cambios a administración: dirección(es) separadas por
     * comas a las que {@see \App\Command\SendAdminDeliveryChangesSummaryCommand} manda el digest.
     * Evita tener que tocar el crontab de cdmon (solo-FTP): el cron corre sin `--to` y el comando
     * cae en este ajuste. Vacío = no se envía. La opción `--to` de la línea de comandos, si se
     * pasa, tiene prioridad sobre este ajuste.
     */
    public const EMAIL_ADMIN_DELIVERY_SUMMARY_TO = 'email.admin_delivery_summary_to';

    /** Envío del recordatorio de llegadas/salidas del albergue al equipo (app:send-albergue-arrivals-reminder). */
    public const EMAIL_ALBERGUE_REMINDER = 'email.albergue_reminder';

    /** Envío de los avisos de huecos del registro de jornada al supervisor (digest semanal + salida abierta). */
    public const EMAIL_STAFF_GAPS = 'email.staff_gaps';

    /** Envío del listado de reparto en PDF al cerrarse el plazo de cada nodo (app:send-delivery-sheets). */
    public const EMAIL_DELIVERY_SHEET = 'email.delivery_sheet';

    /** Confirmación a cada socix de su cesta al cerrarse el plazo de su nodo (app:send-delivery-confirmations). */
    public const EMAIL_DELIVERY_CONFIRMATION = 'email.delivery_confirmation';

    /**
     * Red de seguridad para entornos de prueba: si tiene valor, TODOS los emails
     * que envía la app se entregan SOLO a esa(s) dirección(es) — separadas por
     * comas —, sin importar el destinatario original (que sigue visible en la
     * cabecera To). La aplica {@see \App\Mailer\RedirectRecipientsListener}.
     * Vacío (default) = sin redirección, cada email va a su destinatario real:
     * así DEBE quedar en producción. Se edita desde la pantalla de diagnóstico
     * de envíos, no desde el form general de ajustes.
     */
    /**
     * Destinatario(s) del recordatorio del albergue. Su comando exige `--to`, y
     * sin este ajuste sólo se podía indicar en la línea del crontab del hosting
     * — que no vemos ni podemos editar. Por eso esa tarea nunca llegó a correr.
     */
    public const EMAIL_ALBERGUE_REMINDER_TO = 'email.albergue_reminder_to';

    /**
     * Destinatario(s) de los dos avisos de jornada (huecos y salidas sin
     * cerrar). Uno solo para los dos, igual que comparten interruptor: los dos
     * van a quien supervisa.
     */
    public const EMAIL_STAFF_GAPS_TO = 'email.staff_gaps_to';

    /**
     * Destinatario(s) del listado de reparto que sale al cerrar el plazo de cada
     * nodo. Hoy es quien monta las cestas y abre el punto de recogida — la misma
     * gente que ya lo imprime—, y por eso es una lista de direcciones y no una
     * audiencia calculada: a quién debe llegar el listado es una decisión de la
     * asociación, no del código, y mientras no esté tomada se rellena a mano.
     */
    public const EMAIL_DELIVERY_SHEET_TO = 'email.delivery_sheet_to';

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
     * ¿Está abierto el módulo de voluntariado (ofertas de trabajo, inscripción
     * de socixs y el bloque del panel)? Apagado, se oculta del menú, sus rutas
     * responden 403 y no sale ningún aviso. Lo resuelve
     * {@see \App\Security\FeatureVoter} vía {@see is_granted('FEATURE_VOLUNTEERING')}.
     */
    public const FEATURE_VOLUNTEERING = 'feature.volunteering';

    /**
     * Horas que espera el escalado antes de abrir un aviso de voluntariado a
     * más gente ({@see \App\Service\Volunteering\VolunteerCallEscalator}).
     */
    public const VOLUNTEERING_ESCALATION_HOURS = 'volunteering.escalation_hours';

    /**
     * Con cuánta antelación se le recuerda a quien se apuntó que le toca.
     */
    public const VOLUNTEERING_REMINDER_HOURS = 'volunteering.reminder_hours';

    /**
     * ¿Está abierto el módulo del grupo de consumo (productores, rondas de pedido
     * colectivo y apuntes de socixs)? Apagado, se oculta del menú (gestión y panel)
     * y sus rutas responden 403. Lo resuelve {@see \App\Security\FeatureVoter} vía
     * {@see is_granted('FEATURE_GRUPO_CONSUMO')}. Arranca OFF (rodaje).
     */
    public const FEATURE_GRUPO_CONSUMO = 'feature.grupo_consumo';

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

    /**
     * Aviso semanal de las fichas de socix a las que les faltan datos: a cada
     * socix lo que puede rellenar elle, y a quien coordina socixs cuántas fichas
     * están a medias (app:notify-incomplete-profiles). SÓLO por la bandeja de
     * avisos.
     */
    public const CRON_INCOMPLETE_PROFILES = 'cron.incomplete_profiles';
    public const CRON_GENERATE_WEEKLY_DELIVERY = 'cron.generate_weekly_delivery';
    public const CRON_ALBERGUE_REMINDER = 'cron.albergue_reminder';

    /** Tarea del digest SEMANAL de huecos del registro de jornada (app:send-staff-gaps-digest). */
    public const CRON_STAFF_GAPS_DIGEST = 'cron.staff_gaps_digest';

    /** Tarea del aviso de salida abierta del registro de jornada (app:send-staff-open-shift-alert). */
    public const CRON_STAFF_OPEN_SHIFT_ALERT = 'cron.staff_open_shift_alert';

    /**
     * Envío del listado de reparto en cuanto se cierra el plazo de cambios de
     * cada nodo (app:send-delivery-sheets). Cadencia diaria y no semanal porque
     * cada nodo cierra su propio día: Madrid el martes por la noche y la Sierra
     * el jueves, así que un disparo semanal sólo podría servir a uno de los dos.
     */
    public const CRON_DELIVERY_SHEET = 'cron.delivery_sheet';

    /**
     * Confirmación a lxs socixs de su cesta en cuanto cierra el plazo de su nodo
     * (app:send-delivery-confirmations). Comparte disparador con el listado pero
     * es tarea aparte: van a destinatarios distintos, y apagar el listado interno
     * no debe callar el aviso a lxs socixs ni al revés.
     */
    public const CRON_DELIVERY_CONFIRMATION = 'cron.delivery_confirmation';

    /**
     * Avisos de voluntariado: la tarea que abre por pasos las llamadas pidiendo
     * gente. Es la única de cadencia fina (por intervalo) porque un "falta gente
     * para mañana" no admite esperar al día siguiente.
     */
    public const CRON_VOLUNTEER_CALLS = 'cron.volunteer_calls';

    /**
     * Recordatorio a quien ya se apuntó a una tarea de voluntariado, poco antes
     * de que le toque. Separado del de las llamadas porque son avisos
     * distintos: aquél pide gente, éste recuerda a quien ya dijo que sí, y se
     * puede querer uno sin el otro.
     */
    public const CRON_VOLUNTEER_REMINDERS = 'cron.volunteer_reminders';

    /**
     * MANIFIESTO DE TAREAS PROGRAMADAS: la fuente única de verdad sobre qué
     * debería ejecutarse, cuándo, y qué la inhibe. Clave del toggle =>
     * metadatos. Lo lee {@see \App\Service\Cron\CronTaskRegistry}.
     *
     * Antes esta información vivía repartida en tres sitios que no se hablaban:
     * dos `if` copiados dentro de cada comando (el gate), las líneas del crontab
     * de un hosting sin SSH (la cadencia) y el texto de ayuda de la pantalla
     * (las dependencias). El resultado fue que ninguna tarea corrió en
     * producción entre el 20 de julio y el 4 de agosto de 2026 sin que nada
     * avisara. Con el manifiesto, el sistema puede responder qué debería estar
     * pasando y compararlo con lo que pasó ({@see \App\Entity\CronRun}).
     *
     * Campos:
     *
     * - `command`: comando de consola asociado. Es también la lista blanca:
     *   sólo se puede lanzar a mano lo declarado aquí.
     * - `confirm`: ENTREGA ALGO REAL A PERSONAS, así que la UI pide
     *   confirmación. Significaba "envía correo" cuando el correo era el único
     *   canal; desde que hay avisos push la confirmación la pide el efecto, no
     *   el medio. Para saber POR DÓNDE entrega, está `channels`.
     * - `channels`: por qué canales entrega ('email', 'push', 'inbox'). Vacío en
     *   las tareas que no avisan a nadie. Existe porque `confirm` dejó de servir
     *   para distinguirlo: las reglas de los toggles de correo sólo pueden
     *   exigirse a quien manda correo, y un aviso push no tiene interruptor
     *   general de email que declarar ni un correo que reenviar.
     *   `inbox` es la copia en la bandeja de avisos, y se declara aunque no tenga
     *   interruptor ninguno —es el suelo, siempre activa— porque es justo lo que
     *   hay que saber antes de meter algo en `requires`: una tarea que entrega
     *   también por la bandeja NUNCA puede llevar ahí el ajuste de un canal
     *   suelto, o apagar el correo dejaría sin escribir la copia.
     * - `dry`: ofrece botón de previsualización (--dry-run).
     * - `schedule`: CADENCIA declarada — cuándo debería correr. `freq` es
     *   daily|weekly|monthly, con `dow` (1 = lunes) en las semanales y `dom` en
     *   las mensuales. Hoy la cadencia real la impone el crontab del hosting y
     *   esto es su declaración fiel; cuando exista el tick genérico, este campo
     *   pasa a ser el que MANDA.
     * - `max_delay_hours`: PLAZO MÁXIMO DE RETRASO. Pasado ese tiempo sin
     *   ejecutarse, la tarea se considera caída. Se da margen sobre la cadencia
     *   (un reloj puntual no existe): día y medio para las diarias, ocho días
     *   para las semanales, 33 para las mensuales.
     * - `requires`: ajustes que la habilitan APARTE de su propio interruptor.
     *   Son los toggles de entrega (email): a diferencia del interruptor propio
     *   de la tarea, éstos NO los salta una ejecución manual con --force —
     *   apagar el envío tiene que apagarlo también a mano. Toda tarea que manda
     *   correo declara aquí el interruptor GENERAL ({@see self::EMAIL_ENABLED})
     *   además del suyo, por dos razones: con el general apagado
     *   {@see \App\Mailer\KillSwitchMailer} descarta los mensajes en silencio, y
     *   una tarea que corre entera para no entregar nada (a) se registraría como
     *   "hizo su trabajo" mintiendo en la pantalla y (b) dejaría sus efectos
     *   apuntados en el guardián de idempotencia, de modo que al reencender el
     *   envío esos avisos ya constarían emitidos y no saldrían nunca.
     * - `depends_on`: tareas de las que depende. Sirve para detectar
     *   incoherencias que de otro modo son invisibles: el recordatorio de
     *   recogida sólo lee cestas ya congeladas, así que con el congelado apagado
     *   corre en verde sin avisar a nadie.
     *
     * OJO: sólo las cuatro primeras están en el crontab de producción
     * (`docs/migracion-prod/crons.txt`). Las tres de albergue y jornada laboral
     * declaran su cadencia aquí pero HOY NADIE LAS DISPARA; se pidieron a cdmon
     * y están sin montar.
     */
    public const CRONS = [
        self::CRON_GENERATE_WEEKLY_DELIVERY => [
            'command' => 'app:generate-weekly-delivery',
            'channels' => [],
            'needs_recipient' => false,
            'confirm' => false,
            'dry' => false,
            'schedule' => ['freq' => 'weekly', 'dow' => 1, 'hour' => 6],
            'max_delay_hours' => 192,
            'requires' => [],
            'depends_on' => [],
        ],
        self::CRON_PICKUP_REMINDER => [
            'command' => 'app:send-pickup-reminders',
            'channels' => ['email', 'push', 'inbox'],
            'needs_recipient' => false,
            'confirm' => true,
            'dry' => true,
            'schedule' => ['freq' => 'daily', 'hour' => 9],
            'max_delay_hours' => 36,
            // SIN `requires` DE EMAIL, y no es un olvido. Esta tarea avisa por
            // DOS canales (correo y push), y `requires` inhibe la tarea entera
            // —ni siquiera --force lo salta—, así que apagar el correo del
            // recordatorio dejaría también sin aviso a quien lo tiene activado
            // en el móvil, que no ha pedido nada de eso.
            //
            // Lo que cada canal entrega se decide DENTRO, en su canal:
            //   - el interruptor general (email.enabled) lo corta el
            //     {@see \App\Mailer\KillSwitchMailer}, a nivel de transporte, así
            //     que ningún correo sale aunque la tarea se ejecute;
            //   - el ajuste propio del recordatorio lo comprueba el comando
            //     antes de llamar al mailer.
            // El contrato "apagado el envío, no se entrega" se mantiene entero;
            // lo que deja de arrastrar es el otro canal.
            'requires' => [],
            // Sin congelado no hay destinatarios: el recordatorio lee sólo
            // cestas ya materializadas (WeeklyBasketRepository::findPickedByDeliveryDateAndShares).
            'depends_on' => [self::CRON_GENERATE_WEEKLY_DELIVERY],
        ],
        self::CRON_DELIVERY_SHEET => [
            'command' => 'app:send-delivery-sheets',
            'channels' => ['email'],
            'needs_recipient' => true,
            'confirm' => true,
            'dry' => true,
            // A las 7:00 y a diario: cada nodo cierra su plazo la noche anterior a
            // su reparto, así que la tarea mira cada mañana quién cerró y manda
            // sólo ese listado. Un disparo semanal serviría a un nodo y no al otro.
            'schedule' => ['freq' => 'daily', 'hour' => 7],
            'max_delay_hours' => 36,
            // El canal es UNO (correo con el PDF adjunto), así que sus ajustes sí
            // pueden inhibir la tarea entera sin dejar a nadie a medias. En cuanto
            // este listado salga también por otra vía, esto tiene que vaciarse y
            // comprobarse dentro del comando.
            'requires' => [self::EMAIL_ENABLED, self::EMAIL_DELIVERY_SHEET],
            // El listado que se manda tras el cierre tiene que ser el CONGELADO: si
            // la semana no se materializó, el documento se dibujaría al vuelo y
            // podría no coincidir con lo que quien reparte se encuentre el día del
            // reparto.
            'depends_on' => [self::CRON_GENERATE_WEEKLY_DELIVERY],
        ],
        self::CRON_DELIVERY_CONFIRMATION => [
            'command' => 'app:send-delivery-confirmations',
            'channels' => ['email'],
            // Cada socix recibe la suya: no hay destinatario que configurar.
            'needs_recipient' => false,
            'confirm' => true,
            'dry' => true,
            // A las 7:00, como el listado y por lo mismo: cada nodo cierra su
            // propio día. Va después en el manifiesto para que el listado interno
            // salga primero si ambos corren en la misma pasada.
            'schedule' => ['freq' => 'daily', 'hour' => 7],
            'max_delay_hours' => 36,
            // Canal único, así que sus ajustes pueden inhibir la tarea entera. EN
            // CUANTO ESTA CONFIRMACIÓN SALGA TAMBIÉN POR PUSH hay que vaciar esto
            // y comprobar el toggle del correo DENTRO del comando: `requires`
            // inhibe la tarea completa —ni --force lo salta— y apagar el correo
            // dejaría sin aviso a quien lo tiene activado en el móvil.
            'requires' => [self::EMAIL_ENABLED, self::EMAIL_DELIVERY_CONFIRMATION],
            // Confirma lo que hay en el listado congelado; sin congelar, confirmaría
            // un dibujo que todavía puede moverse.
            'depends_on' => [self::CRON_GENERATE_WEEKLY_DELIVERY],
        ],
        self::CRON_ADMIN_DELIVERY_SUMMARY => [
            'command' => 'app:send-admin-delivery-changes-summary',
            'channels' => ['email'],
            'needs_recipient' => true,
            'confirm' => true,
            'dry' => true,
            'schedule' => ['freq' => 'daily', 'hour' => 20],
            'max_delay_hours' => 36,
            'requires' => [self::EMAIL_ENABLED, self::EMAIL_ADMIN_DELIVERY_SUMMARY],
            'depends_on' => [],
        ],
        self::CRON_PURGE_USAGE_HITS => [
            'command' => 'app:purge-usage-hits',
            'channels' => [],
            'needs_recipient' => false,
            'confirm' => false,
            'dry' => false,
            'schedule' => ['freq' => 'monthly', 'dom' => 1, 'hour' => 4],
            'max_delay_hours' => 792,
            'requires' => [],
            'depends_on' => [],
        ],
        // Los lunes temprano y sólo a la bandeja. `confirm` en false aunque
        // entregue algo a personas: la confirmación de la pantalla existe para lo
        // que SALE de la asociación —un correo no se puede des-enviar, un push
        // tampoco—, y una fila en una bandeja se borra. Y `channels` sólo 'inbox':
        // es lo que impide que alguien meta aquí un `requires` de correo, que
        // inhibiría la tarea entera sin que --force lo salte.
        self::CRON_INCOMPLETE_PROFILES => [
            'command' => 'app:notify-incomplete-profiles',
            'channels' => ['inbox'],
            'needs_recipient' => false,
            'confirm' => false,
            'dry' => true,
            'schedule' => ['freq' => 'weekly', 'dow' => 1, 'hour' => 7],
            'max_delay_hours' => 192,
            // Sin `requires`: no depende de ningún canal apagable. La bandeja es
            // el suelo y no tiene interruptor.
            'requires' => [],
            'depends_on' => [],
        ],
        self::CRON_ALBERGUE_REMINDER => [
            'command' => 'app:send-albergue-arrivals-reminder',
            'channels' => ['email'],
            'needs_recipient' => true,
            'confirm' => true,
            'dry' => true,
            'schedule' => ['freq' => 'daily', 'hour' => 7],
            'max_delay_hours' => 36,
            'requires' => [self::EMAIL_ENABLED, self::EMAIL_ALBERGUE_REMINDER],
            'depends_on' => [],
        ],
        self::CRON_STAFF_GAPS_DIGEST => [
            'command' => 'app:send-staff-gaps-digest',
            'channels' => ['email'],
            'needs_recipient' => true,
            'confirm' => true,
            'dry' => true,
            'schedule' => ['freq' => 'weekly', 'dow' => 1, 'hour' => 8],
            'max_delay_hours' => 192,
            'requires' => [self::EMAIL_ENABLED, self::EMAIL_STAFF_GAPS],
            'depends_on' => [],
        ],
        self::CRON_STAFF_OPEN_SHIFT_ALERT => [
            'command' => 'app:send-staff-open-shift-alert',
            'channels' => ['email'],
            'needs_recipient' => true,
            'confirm' => true,
            'dry' => true,
            'schedule' => ['freq' => 'daily', 'hour' => 10],
            'max_delay_hours' => 36,
            'requires' => [self::EMAIL_ENABLED, self::EMAIL_STAFF_GAPS],
            'depends_on' => [],
        ],
        // La única por intervalo. Las demás tienen su hora del día porque
        // mandan correo y da igual media hora arriba o abajo; ésta abre avisos
        // push por pasos, y con cadencia diaria el segundo paso de una tarea que
        // es pasado mañana llegaría cuando ya no sirve de nada.
        //
        // OJO AL DESPLIEGUE: el intervalo sólo vale si el reloj externo dispara
        // /cron/tick con esa frecuencia. Con el cron del hosting a diario, esto
        // corre una vez al día por mucho que aquí ponga 60 minutos.
        self::CRON_VOLUNTEER_CALLS => [
            'command' => 'app:send-volunteer-calls',
            // Multicanal desde que el aviso también sale por correo. Por eso NO
            // lleva el interruptor de email en `requires`: allí inhibiría la
            // tarea entera y dejaría sin aviso a quien lo quiere en el móvil.
            // `inbox` es la copia de la bandeja, que sale siempre y sin toggle.
            'channels' => ['email', 'push', 'inbox'],
            'needs_recipient' => false,
            'confirm' => true,
            'dry' => true,
            'schedule' => ['freq' => 'interval', 'minutes' => 60],
            'max_delay_hours' => 6,
            'requires' => [self::FEATURE_VOLUNTEERING],
            'depends_on' => [],
        ],
        // También por intervalo: un recordatorio que llega tarde es peor que no
        // mandarlo, porque gasta el canal sin traer a nadie.
        self::CRON_VOLUNTEER_REMINDERS => [
            'command' => 'app:send-volunteer-reminders',
            // El push respeta la preferencia del socix; la copia de la bandeja
            // sale siempre, que es lo que hace que apagar el móvil no signifique
            // no enterarse de una tarea a la que uno mismo se apuntó.
            'channels' => ['push', 'inbox'],
            'needs_recipient' => false,
            'confirm' => true,
            'dry' => true,
            'schedule' => ['freq' => 'interval', 'minutes' => 60],
            'max_delay_hours' => 6,
            'requires' => [self::FEATURE_VOLUNTEERING],
            'depends_on' => [],
        ],
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
            'help' => 'Email a quincenales y mensuales unos días antes de su reparto, con la fecha y el punto de recogida de cada quien (Madrid el miércoles en Cascorro/Midori, la Sierra el viernes en Torremocha).',
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
            'help' => 'Digest periódico con los cambios autoservicio de lxs socixs (saltar cesta, mover, cambiar de nodo, huevos…). Configura la dirección de destino en el campo "Destinatario(s)" de abajo; si lo dejas vacío, no se envía.',
            'default' => true,
        ],
        self::EMAIL_VOLUNTEERING => [
            'group' => 'Envío de emails',
            'label' => 'Avisos de voluntariado por email',
            'help' => 'Manda también por correo los avisos que piden gente para una tarea (además del aviso al móvil). Quien no tenga cuenta de acceso sólo puede enterarse por aquí. Cada socix decide en su panel si lo quiere por correo, por móvil o por los dos; esto es el interruptor general.',
            'default' => false,
        ],
        self::EMAIL_ALBERGUE_REMINDER => [
            'group' => 'Emails internos',
            'label' => 'Recordatorio de llegadas/salidas del albergue',
            'help' => 'Aviso al equipo con las llegadas y salidas confirmadas de los próximos días en el albergue (preparar camas, etc.). Se envía a la dirección configurada en el cron.',
            'default' => false,
        ],
        self::EMAIL_STAFF_GAPS => [
            'group' => 'Emails internos',
            'label' => 'Avisos de huecos del registro de jornada',
            'help' => 'Cubre los dos avisos al supervisor del control horario: el digest semanal con los días laborables sin fichar de cada trabajador, y el aviso de “salida abierta” cuando alguien se deja una entrada sin cerrar de un día anterior. Se envían a la dirección configurada en cada cron.',
            'default' => false,
        ],
        self::EMAIL_DELIVERY_SHEET => [
            'group' => 'Emails internos',
            'label' => 'Listado de reparto al cerrarse el plazo',
            'help' => 'Manda el listado del reparto en PDF en cuanto se cierra el plazo de cambios de cada nodo, a la dirección configurada más abajo. Es el mismo documento que se descarga desde Reparto, generado solo. Apagado, hay que seguir generándolo a mano.',
            'default' => false,
        ],
        self::EMAIL_DELIVERY_CONFIRMATION => [
            'group' => 'Emails a socixs',
            'label' => 'Confirmación de la cesta al cerrarse el plazo',
            'help' => 'Cuando cierra el plazo de cambios de cada nodo, escribe a cada socix diciéndole si esta semana recoge y qué día, o si no recoge porque pidió un cambio. Sirve para que quien creyó haber cambiado algo se entere a tiempo.',
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
        self::FEATURE_GRUPO_CONSUMO => [
            'group' => 'Funcionalidades en rodaje',
            'label' => 'Grupo de consumo',
            'help' => 'Abre el módulo del grupo de consumo: productores, rondas de pedido colectivo y los apuntes de lxs socixs. Apagado, se oculta del menú (gestión y panel) y no es accesible.',
            'default' => false,
        ],
        self::FEATURE_VOLUNTEERING => [
            'group' => 'Funcionalidades en rodaje',
            'label' => 'Voluntariado',
            'help' => 'Abre el módulo de voluntariado: publicar trabajos, que lxs socixs se apunten y el bloque de su panel con lo que hace falta. Apagado, se oculta del menú, no es accesible y no se envía ningún aviso pidiendo gente.',
            'default' => false,
        ],
        self::CRON_VOLUNTEER_REMINDERS => [
            'group' => 'Tareas programadas',
            'label' => 'Recordar el voluntariado a quien se apuntó',
            'help' => 'Avisa a quien se apuntó a una tarea poco antes de que le toque, con la antelación configurada. Sin esto, alguien se apunta con dos semanas y el día que es no se acuerda. Requiere el módulo de voluntariado encendido.',
            'default' => false,
        ],
        self::CRON_VOLUNTEER_CALLS => [
            'group' => 'Tareas programadas',
            'label' => 'Avisar de tareas de voluntariado sin cubrir',
            'help' => 'Abre por pasos los avisos que piden gente: primero a quien ha marcado esa categoría en su ficha y, si sigue faltando gente pasadas unas horas, a quien no ha marcado ninguna (sólo en tareas aptas para cualquiera). Nunca llega solo a todo el mundo: eso se lanza a mano. Requiere el módulo de voluntariado encendido.',
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
            'label' => 'Avisar a socixs de su recogida',
            'help' => 'Avisa a quincenales y mensuales del próximo reparto (app:send-pickup-reminders). Independiente del email del recordatorio: apagada aquí, la tarea ni se ejecuta.',
            'default' => true,
        ],
        self::CRON_DELIVERY_SHEET => [
            'group' => 'Tareas programadas',
            'label' => 'Enviar el listado al cerrar el reparto',
            'help' => 'Cada mañana comprueba qué nodos cerraron su plazo de cambios la noche anterior y manda su listado en PDF (app:send-delivery-sheets). Independiente del email del listado: apagada aquí, la tarea ni se ejecuta.',
            'default' => false,
        ],
        self::CRON_DELIVERY_CONFIRMATION => [
            'group' => 'Tareas programadas',
            'label' => 'Confirmar la cesta a lxs socixs',
            'help' => 'Cada mañana, para los nodos que cerraron su plazo la noche anterior, escribe a cada socix con lo que le queda registrado (app:send-delivery-confirmations). Independiente del email de la confirmación: apagada aquí, la tarea ni se ejecuta.',
            'default' => false,
        ],
        self::CRON_ADMIN_DELIVERY_SUMMARY => [
            'group' => 'Tareas programadas',
            'label' => 'Enviar el resumen a administración',
            'help' => 'Manda a administración el resumen periódico de cambios en el reparto (app:send-admin-delivery-changes-summary). Independiente del email del resumen.',
            'default' => true,
        ],
        self::CRON_PURGE_USAGE_HITS => [
            'group' => 'Tareas programadas',
            'label' => 'Purgar el rastro de uso',
            'help' => 'Borra periódicamente la telemetría de uso anterior al período de retención (app:purge-usage-hits), por minimización de datos. Apagada, el rastro se acumula sin límite.',
            'default' => true,
        ],
        self::CRON_INCOMPLETE_PROFILES => [
            'group' => 'Tareas programadas',
            'label' => 'Avisar de las fichas con datos sin rellenar',
            'help' => 'Cada lunes deja en la bandeja de cada socix los datos que le faltan y puede rellenar elle, y a quien coordina socixs cuántas fichas están a medias. No manda correo ni avisos al móvil: un dato que falta no es urgente. El aviso se renueva sólo si el anterior se leyó y sigue sin arreglarse.',
            'default' => true,
        ],
        self::CRON_ALBERGUE_REMINDER => [
            'group' => 'Tareas programadas',
            'label' => 'Avisar de llegadas y salidas del albergue',
            'help' => 'Avisa al equipo de las llegadas y salidas próximas del albergue, para preparar camas (app:send-albergue-arrivals-reminder). Independiente del email del albergue.',
            'default' => true,
        ],
        self::CRON_STAFF_GAPS_DIGEST => [
            'group' => 'Tareas programadas',
            'label' => 'Enviar el resumen de huecos de jornada',
            'help' => 'Manda al supervisor el resumen de días laborables sin fichar de cada trabajador (app:send-staff-gaps-digest). Semanal a propósito, para no saturar. Independiente del email de jornada.',
            'default' => false,
        ],
        self::CRON_STAFF_OPEN_SHIFT_ALERT => [
            'group' => 'Tareas programadas',
            'label' => 'Avisar de salidas abiertas',
            'help' => 'Avisa al supervisor si algún trabajador se dejó una entrada sin cerrar de un día anterior (app:send-staff-open-shift-alert). Solo envía si hay alguna. Independiente del email de jornada.',
            'default' => false,
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
        self::VOLUNTEERING_REMINDER_HOURS => [
            'group' => 'Funcionalidades en rodaje',
            'label' => 'Antelación del recordatorio de voluntariado',
            'help' => 'Cuántas horas antes se le recuerda a quien se apuntó que le toca. 24 = el día anterior. Requiere que la tarea programada de avisos corra al menos con esa frecuencia.',
            'default' => 24,
            'min' => 1,
            'max' => 168,
            'unit' => 'h',
        ],
        self::VOLUNTEERING_ESCALATION_HOURS => [
            'group' => 'Funcionalidades en rodaje',
            'label' => 'Espera antes de ampliar un aviso de voluntariado',
            'help' => 'Cuántas horas se espera, desde el primer aviso, antes de pedir la misma tarea a socixs que no han declarado preferencias. Sólo se amplía si la tarea está marcada como apta para cualquiera y sigue faltando gente. 24 = al día siguiente.',
            'default' => 24,
            'min' => 1,
            'max' => 168,
            'unit' => 'h',
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
        self::EMAIL_ADMIN_DELIVERY_SUMMARY_TO => [
            'group' => 'Emails internos',
            'label' => 'Destinatario(s) del resumen a administración',
            'help' => 'Dirección(es) de correo (separadas por comas) a las que llega el resumen de cambios de socixs. Vacío = no se envía. Ejemplo: csa@csavegadejarama.org. Así no hace falta tocar el cron del servidor.',
            'default' => '',
            // A diferencia del resto de STRINGS (que viven en pantallas concretas), este SÍ se
            // pinta en el form general de ajustes, junto a su toggle "Resumen de cambios a admin".
            'general' => true,
        ],
        self::EMAIL_ALBERGUE_REMINDER_TO => [
            'group' => 'Emails internos',
            'label' => 'Destinatario(s) del recordatorio del albergue',
            'help' => 'Dirección(es) de correo (separadas por comas) que reciben el aviso de llegadas y salidas próximas. Vacío = la tarea no envía nada aunque esté encendida.',
            'default' => '',
            'general' => true,
        ],
        self::EMAIL_STAFF_GAPS_TO => [
            'group' => 'Emails internos',
            'label' => 'Destinatario(s) de los avisos de jornada',
            'help' => 'Dirección(es) de correo (separadas por comas) que reciben el resumen semanal de huecos y el aviso de salidas sin cerrar. Vacío = esas tareas no envían nada aunque estén encendidas.',
            'default' => '',
            'general' => true,
        ],
        self::EMAIL_DELIVERY_SHEET_TO => [
            'group' => 'Emails internos',
            'label' => 'Destinatario(s) del listado de reparto',
            'help' => 'Dirección(es) de correo (separadas por comas) que reciben el listado del reparto en PDF al cerrarse el plazo de cada nodo. Vacío = la tarea no envía nada aunque esté encendida.',
            'default' => '',
            'general' => true,
        ],
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
