<?php

namespace App\Service\Obligation;

use App\Entity\Obligation;
use App\Repository\ObligationRepository;

/**
 * Quién está a punto de caducar y con cuánta urgencia.
 *
 * ES UN SERVICIO Y NO DOS TROZOS DE CÓDIGO porque lo usan la pantalla y la tarea
 * que avisa. Con una copia en cada sitio acabarían discrepando sobre qué es
 * "urgente", y entonces la pantalla diría que todo está bien mientras el correo
 * avisa de algo, o al revés — que es peor. Mismo motivo por el que
 * {@see \App\Service\Delivery\CancelledDeliveryFilter} se extrajo del comando
 * del recordatorio.
 */
class ObligationWatch
{
    /**
     * Escalones de aviso, en días antes del vencimiento y de más lejano a más
     * cercano. El 0 es el propio día: a partir de ahí ya está caducada y el
     * sitio donde se ve es la pantalla, no un correo diario que nadie leería.
     *
     * Los tres primeros salen del plan de trabajo (90/60/30). El de 7 se añade
     * porque entre "queda un mes" y "se pasó" no hay ningún recordatorio, y un
     * mes es tiempo de sobra para olvidarse.
     *
     * @var list<int>
     */
    public const THRESHOLDS = [90, 60, 30, 7, 0];

    /**
     * A partir de cuántos días se considera que algo "hay que mirarlo", para
     * pintarlo en la pantalla. Es el escalón más lejano: lo que entra en el
     * radar del primer aviso es justo lo que tiene que dejar de estar en verde.
     */
    public const NOTICE_DAYS = self::THRESHOLDS[0];

    public function __construct(private readonly ObligationRepository $obligations)
    {
    }

    /**
     * Lo que ha cruzado algún escalón de aviso y sigue sin renovarse.
     *
     * Para cada obligación se devuelve UN escalón, el más urgente que ya ha
     * alcanzado: algo que vence en 45 días ha cruzado el de 90 y el de 60, y lo
     * que toca decir es "60", no mandar dos correos. Los escalones que se
     * saltaron (porque se dio de alta tarde, o porque el reloj estuvo caído) no
     * se recuperan: avisar hoy de que algo cruzó el escalón de los 90 hace un
     * mes no ayuda a nadie.
     *
     * @param \DateTimeInterface|null $today Día de referencia (por defecto, hoy).
     * @return list<array{obligation: Obligation, threshold: int, days_left: int}>
     */
    public function dueNotices(?\DateTimeInterface $today = null): array
    {
        $notices = [];

        foreach ($this->obligations->findExpiringWithin(self::NOTICE_DAYS, $today) as $obligation) {
            $daysLeft = $obligation->daysLeft($today);
            if ($daysLeft === null) {
                continue;
            }

            $threshold = $this->thresholdFor($daysLeft);
            if ($threshold === null) {
                continue;
            }

            $notices[] = [
                'obligation' => $obligation,
                'threshold' => $threshold,
                'days_left' => $daysLeft,
            ];
        }

        return $notices;
    }

    /**
     * El escalón más urgente ya alcanzado, o null si aún no se ha cruzado
     * ninguno.
     *
     * @param int $daysLeft Días hasta el vencimiento (negativo si ya pasó).
     */
    public function thresholdFor(int $daysLeft): ?int
    {
        $crossed = array_filter(self::THRESHOLDS, static fn (int $t): bool => $daysLeft <= $t);

        return $crossed === [] ? null : min($crossed);
    }

    /**
     * Frase corta de cuánto queda, para el asunto del correo y el listado.
     *
     * @param int $daysLeft Días hasta el vencimiento (negativo si ya pasó).
     */
    public function urgencyLabel(int $daysLeft): string
    {
        return match (true) {
            $daysLeft < -1 => sprintf('caducó hace %d días', abs($daysLeft)),
            $daysLeft === -1 => 'caducó ayer',
            $daysLeft === 0 => 'caduca hoy',
            $daysLeft === 1 => 'caduca mañana',
            default => sprintf('caduca en %d días', $daysLeft),
        };
    }
}
