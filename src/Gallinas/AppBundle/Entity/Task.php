<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 1/12/15
 * Time: 21:47
 * Corresponde con tareas de trabajo. Pueden ser tareas que se repiten todos los años, como una siembra de una variedad
 * determinada, o tareas puntuales que hay que realizar en un determinado período de tiempo.
 * Hay múltiples tipos de tareas, que se distinguen con la variable task_type
 *      - puntuales y no tienen fecha, (limpiar el pozo)
 *      - puntuales con fecha obligada (reunión con alguien)
 *      - puntuales con fecha aproximada en mes o semana (ir a por el grano)
 *      - periódicas con un período de repetición dado (echar serrín al compost)
 *      - tarea de cultivo. En este caso contaremos con el mes como el período de trabajo para las tareas. Es decir, para cada tarea se indicará en qué mes
 *          se realiza. Si es puntual, el año se determinará automáticamente, siendo el siguiente posible a partir de la fecha de creación.
 *
 *
 *
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Task
 * @ORM\Table(name="task")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\TaskRepository")
 */
class Task
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
     * @var smallint $labour
     * @ORM\ManyToOne(targetEntity="Labour", inversedBy="tasks")
     */
    private $labour;


    /**
     * @var smallint $task_type
     * @ORM\ManyToOne(targetEntity="TaskType", inversedBy="tasks")
     */
    private $task_type;

    /**
     * @var string $title
     * @Assert\NotBlank
     * @ORM\Column(name="title", type="string", length=255)
     */
    private $title;

    /**
     * Devuelve una lista de cultivos a las que se asocia la tarea
     * @ORM\ManyToMany(targetEntity="CropWorking", inversedBy="tasks")
     * @ORM\JoinTable(name="crop_working_task")
     */
    private $crop_workings;

    /**
     * Devuelve una lista de cultivos a las que se asocia la tarea
     * @ORM\ManyToMany(targetEntity="User", inversedBy="tasks")
     * @ORM\JoinTable(name="user_task")
     */
    private $users;

    /**
     * @var string $content
     * @Assert\NotBlank
     * @ORM\Column(name="content", type="text")
     */
    private $content;

    /**
     * @ORM\OneToMany(targetEntity="TaskNote", mappedBy="task")
     */
    protected $notes;


    /**
     * @ORM\OneToMany(targetEntity="TaskImage", mappedBy="task")
     */
    protected $images;

    /**
     *
     *
     * @var smallint $month
     * @ORM\Column(name="month", type="integer", length=2,nullable=true)
     */
    private $month;

    /**
     *
     * @var smallint $year
     * @ORM\Column(name="year", type="integer", length=4, nullable=true)
     */
    private $year;

    /**
     * la tarea se repite cada X días
     * @var smallint $period
     * @ORM\Column(name="period", type="integer", length=4, nullable=true)
     */
    private $period;

    /**
     * Indica si está finalizada la tarea
     * @ORM\Column(name="finish", type="boolean", length=1, nullable=false)
     * @var boolean finish
     */
    private $finish = 0;


    /**
     * Fecha prevista de realización, en el caso que sea necesario
     * @Assert\Date()
     * @var string $expected_date
     * @ORM\Column(name="expected_date", type="date", nullable=true)
     */
    private $expected_date;

    /**
     * Fecha exacta en la que se realizó completamente la tarea. Si dura más de dos días, se pondrá el día en que se terminó. En las notas ya se
     * verá que dura más de un día
     * @Assert\Date()
     * @var string $real_date
     * @ORM\Column(name="real_date", type="date", nullable=true)
     */
    private $real_date;


    /**
     * @var dateTime $created
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var dateTime $updated
     *
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime")
     */
    private $updated;


    public function __toString()
    {
        return $this->getTitle();
    }


    public function getDate()
    {
        return $this->getExpectedDate();
    }

    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->crop_workings = new \Doctrine\Common\Collections\ArrayCollection();
        $this->notes = new \Doctrine\Common\Collections\ArrayCollection();
        $this->images = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set title
     *
     * @param string $title
     *
     * @return Task
     */
    public function setTitle($title)
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Get title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Set content
     *
     * @param string $content
     *
     * @return Task
     */
    public function setContent($content)
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Get content
     *
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * Set month
     *
     * @param integer $month
     *
     * @return Task
     */
    public function setMonth($month)
    {
        $this->month = $month;

        return $this;
    }

    /**
     * Get month
     *
     * @return integer
     */
    public function getMonth()
    {
        return $this->month;
    }

    /**
     * Set year
     *
     * @param integer $year
     *
     * @return Task
     */
    public function setYear($year)
    {
        $this->year = $year;

        return $this;
    }

    /**
     * Get year
     *
     * @return integer
     */
    public function getYear()
    {
        return $this->year;
    }

    /**
     * Set period
     *
     * @param integer $period
     *
     * @return Task
     */
    public function setPeriod($period)
    {
        $this->period = $period;

        return $this;
    }

    /**
     * Get period
     *
     * @return integer
     */
    public function getPeriod()
    {
        return $this->period;
    }

    /**
     * Set finish
     *
     * @param boolean $finish
     *
     * @return Task
     */
    public function setFinish($finish)
    {
        $this->finish = $finish;

        return $this;
    }

    /**
     * Get finish
     *
     * @return boolean
     */
    public function getFinish()
    {
        return $this->finish;
    }

    /**
     * Set expectedDate
     *
     * @param \DateTime $expectedDate
     *
     * @return Task
     */
    public function setExpectedDate($expectedDate)
    {
        $this->expected_date = $expectedDate;

        return $this;
    }

    /**
     * Get expectedDate
     *
     * @return \DateTime
     */
    public function getExpectedDate()
    {
        return $this->expected_date;
    }

    /**
     * Set realDate
     *
     * @param \DateTime $realDate
     *
     * @return Task
     */
    public function setRealDate($realDate)
    {
        $this->real_date = $realDate;

        return $this;
    }

    /**
     * Get realDate
     *
     * @return \DateTime
     */
    public function getRealDate()
    {
        return $this->real_date;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Task
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set updated
     *
     * @param \DateTime $updated
     *
     * @return Task
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * Get updated
     *
     * @return \DateTime
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Set labour
     *
     * @param \Gallinas\AppBundle\Entity\Labour $labour
     *
     * @return Task
     */
    public function setLabour(\Gallinas\AppBundle\Entity\Labour $labour = null)
    {
        $this->labour = $labour;

        return $this;
    }

    /**
     * Get labour
     *
     * @return \Gallinas\AppBundle\Entity\Labour
     */
    public function getLabour()
    {
        return $this->labour;
    }

    /**
     * Set taskType
     *
     * @param \Gallinas\AppBundle\Entity\TaskType $taskType
     *
     * @return Task
     */
    public function setTaskType(\Gallinas\AppBundle\Entity\TaskType $taskType = null)
    {
        $this->task_type = $taskType;

        return $this;
    }

    /**
     * Get taskType
     *
     * @return \Gallinas\AppBundle\Entity\TaskType
     */
    public function getTaskType()
    {
        return $this->task_type;
    }

    /**
     * Add cropWorking
     *
     * @param \Gallinas\AppBundle\Entity\CropWorking $cropWorking
     *
     * @return Task
     */
    public function addCropWorking(\Gallinas\AppBundle\Entity\CropWorking $cropWorking)
    {
        $this->crop_workings[] = $cropWorking;

        return $this;
    }

    /**
     * Remove cropWorking
     *
     * @param \Gallinas\AppBundle\Entity\CropWorking $cropWorking
     */
    public function removeCropWorking(\Gallinas\AppBundle\Entity\CropWorking $cropWorking)
    {
        $this->crop_workings->removeElement($cropWorking);
    }

    /**
     * Get cropWorkings
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCropWorkings()
    {
        return $this->crop_workings;
    }

    /**
     * Add note
     *
     * @param \Gallinas\AppBundle\Entity\TaskNote $note
     *
     * @return Task
     */
    public function addNote(\Gallinas\AppBundle\Entity\TaskNote $note)
    {
        $this->notes[] = $note;

        return $this;
    }

    /**
     * Remove note
     *
     * @param \Gallinas\AppBundle\Entity\TaskNote $note
     */
    public function removeNote(\Gallinas\AppBundle\Entity\TaskNote $note)
    {
        $this->notes->removeElement($note);
    }

    /**
     * Get notes
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * Add image
     *
     * @param \Gallinas\AppBundle\Entity\TaskImage $image
     *
     * @return Task
     */
    public function addImage(\Gallinas\AppBundle\Entity\TaskImage $image)
    {
        $this->images[] = $image;

        return $this;
    }

    /**
     * Remove image
     *
     * @param \Gallinas\AppBundle\Entity\TaskImage $image
     */
    public function removeImage(\Gallinas\AppBundle\Entity\TaskImage $image)
    {
        $this->images->removeElement($image);
    }

    /**
     * Get images
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getImages()
    {
        return $this->images;
    }

    public function getStatus()
    {
        if ($this->finish==0)
        {
            return "Pendiente";
        }

        return "Finalizada";
    }

    /**
     * Add user
     *
     * @param \Gallinas\AppBundle\Entity\User $user
     *
     * @return Task
     */
    public function addUser(\Gallinas\AppBundle\Entity\User $user)
    {
        $this->users[] = $user;

        return $this;
    }

    /**
     * Remove user
     *
     * @param \Gallinas\AppBundle\Entity\User $user
     */
    public function removeUser(\Gallinas\AppBundle\Entity\User $user)
    {
        $this->users->removeElement($user);
    }

    /**
     * Get users
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getUsers()
    {
        return $this->users;
    }

    public function listUsers()
    {
        $list_users='';

        foreach ($this->getUsers() as $user)
        {
        $list_users.=$user->getUserName(). ", ";
        }

        return $list_users;
    }
}
