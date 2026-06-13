<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ajuste de configuración de la app (clave-valor), editable en caliente desde
 * /gestion/settings. Existe porque el hosting es FTP sin SSH: cambiar un
 * parámetro de entorno en producción exige un mini-deploy, y cosas como "abrir
 * el acceso a socixs nuevxs" o "activar el email de recordatorio" tienen que
 * poder encenderse y apagarse al momento.
 *
 * El catálogo de claves conocidas, defaults y etiquetas vive en
 * {@see \App\Service\AppSettings}; esta entidad sólo persiste los overrides.
 *
 * @ORM\Table(name="setting")
 * @ORM\Entity(repositoryClass="App\Repository\SettingRepository")
 */
class Setting
{
    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * Clave del ajuste, p. ej. "access.self_registration".
     *
     * @ORM\Column(name="name", type="string", length=100, unique=true)
     */
    private string $name = '';

    /**
     * Valor serializado como string ("1"/"0" para booleanos). NULL equivale a
     * "sin override": se aplica el default del catálogo.
     *
     * @ORM\Column(name="value", type="string", length=255, nullable=true)
     */
    private ?string $value = null;

    /**
     * @return int|null Identificador autogenerado.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string Clave del ajuste.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name Clave del ajuste.
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string|null Valor serializado, o null si no hay override.
     */
    public function getValue(): ?string
    {
        return $this->value;
    }

    /**
     * @param string|null $value Valor serializado.
     */
    public function setValue(?string $value): self
    {
        $this->value = $value;
        return $this;
    }
}
