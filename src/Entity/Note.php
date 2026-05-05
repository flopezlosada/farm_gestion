<?php
/**
 * Anotaciones del día a día. Su vocación es dejar anotada toda la información que surja del trabajo.
 * Created by diphda.net.
 * User: paco
 * Date: 1/12/15
 * Time: 22:07
 */

namespace App\Entity;


use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\Note
 * @ORM\Table(name="note")
 * @ORM\Entity(repositoryClass="App\Repository\NoteRepository")
 * @ORM\HasLifecycleCallbacks
 */
class Note
{
 /**
     * @var integer $id
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }
}
