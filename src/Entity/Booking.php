<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass="App\Repository\BookingRepository")
 * @ORM\HasLifecycleCallbacks
 */
class Booking
{
    /**
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     * @ORM\Id
     */
    private $id;

    /**
     * @ORM\Column(type="datetime")
     */
    private $beginAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $endAt = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $title;

    /**
     * @var string $content
     * @ORM\Column(name="content", type="text")
     */
    private $content;

    /**
     * @var string $image
     *
     * @ORM\Column(name="image", type="string", length=255, nullable=true)
     */
    private $image;

    // Assert\Image y no Assert\File: el cartel se pinta como <img> en la
    // agenda pública; con File a secas se colaban .pdf/.docx (eventos 1 y 2
    // históricos) que renderizan como imagen rota.
    #[Assert\Image(maxSize: 6000000)]
    protected $file;

    /**
     * @var int modified
     * @ORM\Column(name="modified", type="boolean", length=1, nullable=true)
     * Lo uso para, cuando se edita la entidad y sólo se modifica el archivo de imagen, indicar que se ha modificado
     * y persistir la entidad, porque el atributo file no se controla mediante doctrine porque no está en la bbdd
     */
    private $modified;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBeginAt()
    {
        return $this->beginAt;
    }

    public function setBeginAt($beginAt)
    {
        $this->beginAt = $beginAt;

        return $this;
    }

    public function getEndAt()
    {
        return $this->endAt;
    }

    public function setEndAt($endAt = null)
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getAbsolutePath()
    {
        return null === $this->image ? null : $this->getUploadRootDir() . '/' . $this->image;
    }

    public function getWebPath()
    {
        return null === $this->image ? null : $this->getUploadDir() . '/' . $this->image;
    }

    /**
     * Indica si el archivo subido es una imagen renderizable como &lt;img&gt;.
     *
     * Los eventos 1 y 2 históricos guardaron .docx/.pdf antes de que el form
     * forzara Assert\Image; para esos hay que evitar pintar una imagen rota
     * (en el detalle se ofrecen como enlace de descarga en su lugar).
     *
     * @return bool true si la extensión del fichero es de imagen.
     */
    public function isImage(): bool
    {
        if (null === $this->image) {
            return false;
        }

        $extension = strtolower(pathinfo($this->image, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true);
    }

    protected function getUploadRootDir()
    {
        // la ruta absoluta del directorio donde se deben guardar los archivos cargados
        return __DIR__ . '/../../public/' . $this->getUploadDir();
    }

    protected function getUploadDir()
    {
        // se libra del __DIR__ para no desviarse al mostrar `doc/image` en la vista.
        return 'uploads/booking/images';
    }

    /**
     * @ORM\PrePersist()
     * @ORM\PreUpdate()
     */
    public function preUpload()
    {
        if (null !== $this->file)
        {
            // haz cualquier cosa para generar un nombre único
            if ($this->image !== null)
            {
                $this->removeUpload();
            }
            $this->image = uniqid("", true) . '.' . $this->file->guessExtension();
        }
    }

    /**
     * @ORM\PostPersist()
     * @ORM\PostUpdate()
     */
    public function upload()
    {
        // la propiedad file puede estar vacía si el campo no es obligatorio
        if (null === $this->file)
        {
            return;
        }

        // si hay un error al mover el archivo, move() automáticamente
        // envía una excepción. Esta impedirá que la entidad se persista
        // en la base de datos en caso de error
        $this->file->move($this->getUploadRootDir(), $this->image);

        unset($this->file);
    }

    public function getFile()
    {
        return $this->file;
    }

    public function setFile($file)
    {
        $this->file = $file;
    }

    /**
     * @ORM\PostRemove()
     */
    public function removeUpload()
    {
        if ($file = $this->getAbsolutePath())
        {
            unlink($file);
        }
    }

    /**
     * Set image
     *
     * @param string $image
     *
     * @return Blog
     */
    public function setImage($image)
    {
        $this->image = $image;

        return $this;
    }

    /**
     * Get image
     *
     * @return string
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * Set modified
     *
     * @param boolean $modified
     *
     * @return Blog
     */
    public function setModified($modified)
    {
        $this->modified = $modified;

        return $this;
    }

    /**
     * Get modified
     *
     * @return boolean
     */
    public function getModified()
    {
        return $this->modified;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getDate()
    {
        return $this->getBeginAt();
    }
}