<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Proyecto dinamizado desde el LAR (Laboratorio Agroecológico Rural).
 *
 * El LAR es la cara pública y pedagógica del espacio de La Cerrada: acoge
 * proyectos con vida propia (Campus Rural, formación IMIDRA, wwoofing,
 * proyectos nacionales e internacionales…). Cada uno es una FICHA rica —un
 * título, un tipo, un resumen para la tarjeta y un cuerpo largo con TinyMCE—
 * más una galería de fotos/vídeos que cuelga por el sistema de media
 * polimórfico ({@see Image}/{@see Video}) con object_class = "larproject".
 *
 * NO es un post de blog: es su propio dominio. Se reutiliza infraestructura
 * (media, editor, mailer del formulario) pero el concepto es independiente.
 *
 * El espacio físico (La Cerrada, aforo, estancias) se gestiona aparte en el
 * módulo de albergue ({@see Helper}/{@see Stay}); aquí solo vive el escaparate.
 *
 * @ORM\Table(name="lar_project")
 * @ORM\Entity(repositoryClass="App\Repository\LarProjectRepository")
 */
class LarProject
{
    /**
     * Clave con la que se etiquetan las imágenes de un proyecto en la tabla de
     * media polimórfica (`image.object_class`).
     */
    public const OBJECT_CLASS = 'larproject';

    /** Proyecto en marcha: se muestra destacado en la web. */
    public const STATUS_ACTIVE = 'active';

    /** Proyecto ya terminado: se conserva como memoria/trayectoria. */
    public const STATUS_FINISHED = 'finished';

    /**
     * Tipos de proyecto que se dinamizan desde el LAR. Clave interna =>
     * etiqueta legible. Es una lista fija (no una entidad) porque cambia poco
     * y así se pintan badges consistentes; si algún día hace falta gestionarla,
     * se promueve a catálogo.
     */
    public const TYPES = [
        'campus_rural' => 'Campus Rural',
        'formacion_imidra' => 'Formación IMIDRA',
        'wwoofing' => 'Wwoofing',
        'nacional' => 'Proyecto nacional',
        'internacional' => 'Proyecto internacional',
        'otro' => 'Otro',
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
     * @var string
     * @ORM\Column(name="title", type="string", length=255)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private $title;

    /**
     * Slug derivado del título para la URL pública del detalle (/lar/{slug}).
     *
     * @var string
     * @Gedmo\Slug(fields={"title"})
     * @ORM\Column(name="slug", type="string", length=255, unique=true)
     */
    private $slug;

    /**
     * Tipo de proyecto. Una de las claves de {@see self::TYPES}.
     *
     * @var string
     * @ORM\Column(name="type", type="string", length=32)
     */
    #[Assert\NotBlank]
    #[Assert\Choice(callback: 'typeKeys')]
    private $type = 'otro';

    /**
     * Resumen breve para la tarjeta del listado público. Texto plano.
     *
     * @var string
     * @ORM\Column(name="summary", type="string", length=500)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    private $summary;

    /**
     * Cuerpo largo del proyecto (HTML del editor TinyMCE). Es el contenido de
     * la página de detalle.
     *
     * @var string|null
     * @ORM\Column(name="content", type="text", nullable=true)
     */
    private $content;

    /**
     * Estado: activo (en marcha) o finalizado (memoria). Uno de
     * {@see self::STATUS_ACTIVE} / {@see self::STATUS_FINISHED}.
     *
     * @var string
     * @ORM\Column(name="status", type="string", length=16, options={"default": "active"})
     */
    #[Assert\Choice(choices: [self::STATUS_ACTIVE, self::STATUS_FINISHED])]
    private $status = self::STATUS_ACTIVE;

    /**
     * ¿Visible en la web? Permite ir preparando un proyecto en borrador sin
     * que se muestre hasta que esté listo.
     *
     * @var bool
     * @ORM\Column(name="published", type="boolean", options={"default": false})
     */
    private $published = false;

    /**
     * Orden en el listado y en la web (menor = antes); empate por fecha (created
     * DESC). De momento NO se edita desde el panel —el formulario no lo expone—,
     * así que los proyectos creados a mano nacen todos con 0 y se ordenan por
     * fecha. Se reactivará cuando el listado permita reordenar con arrastrar y
     * soltar (deuda pendiente); por eso el campo se conserva.
     *
     * @var int
     * @ORM\Column(name="position", type="integer", options={"default": 0})
     */
    #[Assert\NotNull]
    private $position = 0;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime")
     */
    private $updated;

    /**
     * Claves válidas de tipo, para la validación de {@see $type}.
     *
     * @return string[]
     */
    public static function typeKeys(): array
    {
        return array_keys(self::TYPES);
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
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * @param string $title
     * @return self
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getSlug(): ?string
    {
        return $this->slug;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Etiqueta legible del tipo (para pintar en la web). Si el tipo no está en
     * el catálogo, devuelve la clave cruda como salvavidas.
     *
     * @return string
     */
    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * @return string|null
     */
    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * @param string $summary
     * @return self
     */
    public function setSummary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * @param string|null $content
     * @return self
     */
    public function setContent(?string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     * @return self
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return bool
     */
    public function isPublished(): bool
    {
        return $this->published;
    }

    /**
     * @param bool $published
     * @return self
     */
    public function setPublished(bool $published): self
    {
        $this->published = $published;

        return $this;
    }

    /**
     * @return int
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * @param int $position
     * @return self
     */
    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    /**
     * @return \DateTime|null
     */
    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    /**
     * @return \DateTime|null
     */
    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->title;
    }
}
