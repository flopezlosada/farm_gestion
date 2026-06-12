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
 * ayuda (lo que ve la administración en la pantalla /gestion/configuracion) y
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

    /**
     * Antelación (en días sobre la fecha del reparto) con la que se envía el
     * recordatorio de recogida. La lee {@see \App\Command\SendPickupReminderCommand}.
     */
    public const PICKUP_REMINDER_DAYS_BEFORE = 'email.pickup_reminder_days_before';

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
     * Catálogo de ajustes booleanos: clave => [grupo, etiqueta, ayuda, default].
     * La pantalla de configuración se construye desde aquí; añadir un ajuste
     * nuevo es añadir una entrada (y leerla donde toque).
     */
    public const BOOLEANS = [
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
            'group' => 'Cambios de socixs',
            'label' => 'Cierre de cambios: antelación',
            'help' => 'Cuántos días antes del reparto se cierra el plazo para que un socix pida un cambio puntual (saltar, cambiar de viernes o de nodo). 1 = el día anterior al reparto.',
            'default' => 1,
            'min' => 0,
            'max' => 7,
            'unit' => 'días',
        ],
    ];

    /**
     * Catálogo de ajustes de hora ("HH:MM", 24h): clave => [grupo, etiqueta,
     * ayuda, default]. Se editan con un selector de hora nativo y se validan
     * con {@see self::TIME_PATTERN} al guardar.
     */
    public const TIMES = [
        self::DEADLINE_TIME => [
            'group' => 'Cambios de socixs',
            'label' => 'Cierre de cambios: hora',
            'help' => 'A qué hora del día de cierre termina el plazo para pedir un cambio puntual. La administración siempre puede forzar un cambio fuera de plazo.',
            'default' => '23:59',
        ],
    ];

    /** Valida una hora "HH:MM" en 24h (00:00–23:59). */
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

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
