<?php
namespace Gallinas\AppBundle\Entity;

use ADesigns\CalendarBundle\Entity\EventEntity as CalendarEventEntity;
use Symfony\Bundle\FrameworkBundle\Routing\Router;

class TaskEntity extends CalendarEventEntity
{
    protected $bgColor = '#95992A';

    public function __construct(Router $router,$id, $title, \DateTime $startDatetime)
    {

        parent::__construct($title, $startDatetime, null, true);

        $this->setId($id);
        $this->generateUrl($router);

    }


    public function generateUrl(Router $router)
    {
        $this->url = $router->generate("task_show", array('id' => $this->getId()));
    }

    public function toArray()
    {
        $event = array();

        if ($this->id !== null)
        {
            $event['id'] = $this->id;
        }

        $event['title'] = $this->title;
        $event['start'] = $this->startDatetime->format("Y-m-d\TH:i:sP");

        if ($this->url !== null)
        {
            $event['url'] = $this->url;
        }

        if ($this->bgColor !== null)
        {
            $event['backgroundColor'] = $this->bgColor;
            $event['borderColor'] = $this->bgColor;
        }

        if ($this->fgColor !== null)
        {
            $event['textColor'] = $this->fgColor;
        }

        if ($this->cssClass !== null)
        {
            $event['cssClass'] = $this->cssClass;
        }

        if ($this->endDatetime !== null)
        {
            $event['end'] = $this->endDatetime->format("Y-m-d\TH:i:sP");
        }

        $event['allDay'] = $this->allDay;

        return $event;
    }
}