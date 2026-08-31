<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contenido editable de la portada del LAR (Laboratorio Agroecológico Rural).
 *
 * Es un SINGLETON: hay una sola fila, la portada. Modela los bloques de texto
 * que antes vivían fijos en la plantilla y que la coordinación necesita poder
 * cambiar sin tocar código: la introducción (HTML de TinyMCE), los datos de
 * contacto de la coordinación y las tarjetas de oferta formativa
 * ({@see LarOffer}, en número variable).
 *
 * El email de coordinación ({@see $coordEmail}) es doble fuente de verdad: se
 * muestra en la portada Y es el destino del formulario de petición de
 * información (antes una constante en el controlador).
 *
 * Los valores "de fábrica" (el contenido que ya había en la web) viven en las
 * constantes DEFAULT_* y los aplica el factory {@see self::createWithDefaults()},
 * que usa el repositorio cuando la fila aún no existe. Así la web nunca sale
 * vacía y no se duplican en la migración. El CONSTRUCTOR se mantiene mínimo (solo
 * inicializa la colección) para no interferir con la hidratación de Doctrine.
 *
 * @ORM\Table(name="lar_page")
 * @ORM\Entity(repositoryClass="App\Repository\LarPageRepository")
 */
class LarPage
{
    /** Introducción de fábrica (los tres párrafos que describen La Cerrada). */
    public const DEFAULT_INTRO = "<p>El LAR CSA Vega de Jarama nace para dinamizar el espacio de alojamiento de <strong>La Cerrada</strong>, en Torremocha de Jarama, junto a la vega del Jarama donde se ubican nuestras fincas. Es un lugar propicio para desarrollar proyectos educativos y pedagógicos agroecológicos con distintos grupos: cuenta con aula de formación, habitaciones con 14 plazas para pernocta, aseos y duchas, cocina completa, patio interior y salón-comedor.</p>\n<p>Ofrecemos estancias para todos los públicos, en grupos reducidos de entre 3 y 14 personas, de 2 a 5 días y estancias más prolongadas. Una inmersión pedagógica en nuestra CSA: cómo producimos y distribuimos alimentos, hortícolas y de ganadería aviar, cómo aprovechamos los residuos de la huerta y los tipos de compostaje, la elaboración y el reparto de cestas, y nuestra forma de organizarnos como comunidad.</p>\n<p>Las tarifas están adaptadas a cada grupo y basadas en la economía de la solidaridad y la generosidad. Disponemos de <strong>becas</strong> para colectivos socialmente vulnerabilizados.</p>";

    public const DEFAULT_COORD_NAME = 'Sara Martín Peña';
    public const DEFAULT_COORD_PHONE = '+34 607 511 523';
    public const DEFAULT_COORD_EMAIL = 'larcsa@csavegadejarama.org';

    /** Tarjetas de oferta de fábrica: [título, duración, descripción]. */
    public const DEFAULT_OFFERS = [
        [
            'Inmersión pedagógica',
            '50 h · de lunes a viernes',
            'Experiencia completa sobre el modelo CSA: parte teórica (transición socioecológica, producción y consumo cooperativos, dinamización local, compostaje) y prácticas diarias en las fincas y en las dinámicas de la comunidad.',
        ],
        [
            'Aproximación pedagógica',
            '15 h · de miércoles a viernes',
            'Una aproximación a la experiencia agroecológica: fundamentos del modelo, tipos de agricultura y ganadería asociada, compostaje, y prácticas de apoyo en la huerta y en el reparto de cestas.',
        ],
        [
            'Visita pedagógica',
            '1 día · entre 2 y 6 h',
            'Visita guiada a las fincas de El Sotillo y La Barca para la sensibilización hacia nuevos modelos de producción y consumo. Para todos los públicos, especialmente centros educativos y tercer sector.',
        ],
    ];

    /**
     * @var int|null
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Introducción de la portada (HTML del editor TinyMCE).
     *
     * @var string|null
     * @ORM\Column(name="intro", type="text", nullable=true)
     */
    private $intro;

    /**
     * @var string|null
     * @ORM\Column(name="coord_name", type="string", length=150, nullable=true)
     */
    #[Assert\Length(max: 150)]
    private $coordName;

    /**
     * @var string|null
     * @ORM\Column(name="coord_phone", type="string", length=50, nullable=true)
     */
    #[Assert\Length(max: 50)]
    private $coordPhone;

    /**
     * Email de coordinación: se muestra en la portada y es el destino del
     * formulario de petición de información.
     *
     * @var string|null
     * @ORM\Column(name="coord_email", type="string", length=180, nullable=true)
     */
    #[Assert\Length(max: 180)]
    #[Assert\Email]
    private $coordEmail;

    /**
     * Tarjetas de oferta formativa, ordenadas.
     *
     * @var Collection<int, LarOffer>
     * @ORM\OneToMany(targetEntity="App\Entity\LarOffer", mappedBy="page", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"position" = "ASC"})
     */
    private Collection $offers;

    /**
     * @var \DateTime|null
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime", nullable=true)
     */
    private $updated;

    public function __construct()
    {
        $this->offers = new ArrayCollection();
    }

    /**
     * Construye la portada con el contenido de fábrica (el que ya había en la
     * web), incluidas las tarjetas de oferta. Se usa para servir la web mientras
     * la fila no existe, y como punto de partida editable en el panel.
     *
     * @return self
     */
    public static function createWithDefaults(): self
    {
        $page = new self();
        $page->intro = self::DEFAULT_INTRO;
        $page->coordName = self::DEFAULT_COORD_NAME;
        $page->coordPhone = self::DEFAULT_COORD_PHONE;
        $page->coordEmail = self::DEFAULT_COORD_EMAIL;

        foreach (self::DEFAULT_OFFERS as $i => [$title, $hours, $body]) {
            $page->addOffer(
                (new LarOffer())->setTitle($title)->setHours($hours)->setBody($body)->setPosition($i)
            );
        }

        return $page;
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string|null
     */
    public function getIntro(): ?string
    {
        return $this->intro;
    }

    /**
     * @param string|null $intro
     * @return self
     */
    public function setIntro(?string $intro): self
    {
        $this->intro = $intro;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getCoordName(): ?string
    {
        return $this->coordName;
    }

    /**
     * @param string|null $coordName
     * @return self
     */
    public function setCoordName(?string $coordName): self
    {
        $this->coordName = $coordName;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getCoordPhone(): ?string
    {
        return $this->coordPhone;
    }

    /**
     * @param string|null $coordPhone
     * @return self
     */
    public function setCoordPhone(?string $coordPhone): self
    {
        $this->coordPhone = $coordPhone;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getCoordEmail(): ?string
    {
        return $this->coordEmail;
    }

    /**
     * @param string|null $coordEmail
     * @return self
     */
    public function setCoordEmail(?string $coordEmail): self
    {
        $this->coordEmail = $coordEmail;

        return $this;
    }

    /**
     * @return Collection<int, LarOffer>
     */
    public function getOffers(): Collection
    {
        return $this->offers;
    }

    /**
     * @param LarOffer $offer
     * @return self
     */
    public function addOffer(LarOffer $offer): self
    {
        if (!$this->offers->contains($offer)) {
            $this->offers->add($offer);
            $offer->setPage($this);
        }

        return $this;
    }

    /**
     * @param LarOffer $offer
     * @return self
     */
    public function removeOffer(LarOffer $offer): self
    {
        if ($this->offers->removeElement($offer)) {
            if ($offer->getPage() === $this) {
                $offer->setPage(null);
            }
        }

        return $this;
    }

    /**
     * @return \DateTime|null
     */
    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }
}
