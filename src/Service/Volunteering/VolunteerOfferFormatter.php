<?php

namespace App\Service\Volunteering;

use App\Entity\VolunteerOffer;

/**
 * Cómo se cuenta una tarea en un aviso: cuándo y dónde, en cristiano.
 *
 * Extraído porque tres avisos distintos —el que pide gente, el que informa de un
 * cambio y el que recuerda— dicen lo mismo, y tres copias de un formato de fecha
 * acaban divergiendo: una dice "jueves 4 de septiembre" y otra "04/09/2026", y
 * la misma tarea parece dos.
 *
 * Con Intl y zona horaria explícita, no con `format()`: éste devolvería los días
 * y meses en inglés, y la zona la pondría el `php.ini` del servidor compartido,
 * que ya nos ha mordido antes en el planificador.
 */
class VolunteerOfferFormatter
{
    /** Zona de la gente de esta aplicación. Dato suyo, no del servidor. */
    private const TIMEZONE = 'Europe/Madrid';

    /**
     * "jueves 4 de septiembre, 17:00".
     *
     * @param \DateTimeInterface|null $date la fecha
     *
     * @return string la fecha legible, o cadena vacía si no hay
     */
    public function date(?\DateTimeInterface $date): string
    {
        return null !== $date ? $this->format($date, "EEEE d 'de' MMMM, HH:mm") : '';
    }

    /**
     * "jueves 4 de septiembre a las 17:00", para cuando la frase lo pide.
     *
     * @param \DateTimeInterface|null $date la fecha
     *
     * @return string la fecha legible dentro de una frase
     */
    public function dateInSentence(?\DateTimeInterface $date): string
    {
        return null !== $date ? $this->format($date, "EEEE d 'de' MMMM 'a las' HH:mm") : 'sin fecha';
    }

    /**
     * Dónde es, si se sabe. El nodo manda sobre el texto libre: es el sitio al
     * que esa persona ya va a ir de todas formas.
     *
     * @param VolunteerOffer $offer la tarea
     *
     * @return string|null el lugar, o null si no consta
     */
    public function place(VolunteerOffer $offer): ?string
    {
        if ($offer->isRemote()) {
            return 'desde casa';
        }

        if (null !== $offer->getNode()) {
            return (string) $offer->getNode();
        }

        return $offer->getPlace();
    }

    /**
     * @param \DateTimeInterface $date    la fecha
     * @param string             $pattern el patrón ICU
     *
     * @return string la fecha formateada
     */
    private function format(\DateTimeInterface $date, string $pattern): string
    {
        $formatter = new \IntlDateFormatter(
            'es_ES',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::SHORT,
            self::TIMEZONE,
            \IntlDateFormatter::GREGORIAN,
            $pattern
        );

        return (string) $formatter->format($date);
    }
}
