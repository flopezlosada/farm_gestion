<?php

namespace App\Service\News;

/**
 * Una novedad de la web: algo nuevo que lxs socixs notan al usarla.
 *
 * NO ES UNA ENTIDAD y no tiene tabla. El contenido vive en un fichero del repo
 * ({@see NewsCatalog}) para que viaje con el despliegue: así no se puede
 * anunciar algo que todavía no está en producción, que es el fallo que peor
 * sienta — la gente entra a buscar lo que le has contado y no está.
 *
 * EL `id` ES EL ANCLA, no un adorno. Es lo único que decide qué está anunciado
 * y qué no ({@see NewsAnnouncer}), de ahí que tenga que ser único y creciente.
 * La fecha sólo se enseña.
 */
final readonly class NewsEntry
{
    /**
     * @param int                $id     identificador único y creciente
     * @param \DateTimeImmutable $date   día que se enseña junto a la novedad
     * @param string             $title  una línea, lo que se lee de un vistazo
     * @param string             $text   dos o tres frases explicando qué cambia
     */
    public function __construct(
        public int $id,
        public \DateTimeImmutable $date,
        public string $title,
        public string $text,
    ) {
    }
}
