<?php
/**
 *
 * User: paco
 * Date: 4/11/14
 * Time: 15:50
 */

namespace Gallinas\AppBundle\EventListener;


use ADesigns\CalendarBundle\Event\CalendarEvent;
use Gallinas\AppBundle\Entity\CollectEntity;
use Gallinas\AppBundle\Entity\CulturalWorkEntity;
use Gallinas\AppBundle\Entity\EventEntity;
use Doctrine\ORM\EntityManager;

use Gallinas\AppBundle\Entity\GiftEntity;
use Gallinas\AppBundle\Entity\LayEntity;
use Gallinas\AppBundle\Entity\MovementEntity;
use Gallinas\AppBundle\Entity\PurchaseEntity;
use Gallinas\AppBundle\Entity\SaleEntity;
use Gallinas\AppBundle\Entity\SeedWorkEntity;
use Gallinas\AppBundle\Entity\TaskEntity;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Session\Session;

class CalendarEventListener
{
    private $entityManager;
    private $router;
    private $session;

    public function __construct(EntityManager $entityManager, Router $router, Session $session)
    {
        $this->entityManager = $entityManager;
        $this->router = $router;
        $this->session = $session;
    }

    public function loadEvents(CalendarEvent $calendarEvent)
    {
        $startDate = $calendarEvent->getStartDatetime();
        $endDate = $calendarEvent->getEndDatetime();

        // The original request so you can get filters from the calendar
        // Use the filter in your query for example

        // load events using your custom logic here,
        // for instance, retrieving events from a repository
        //puesta
        $lay_query = $this->entityManager->getRepository('AppBundle:Lay')
            ->createQueryBuilder('lay_events')
            ->where('lay_events.lay_date BETWEEN :startDate and :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'))
            ->getQuery();
        $layEvents = $lay_query->getResult();

        foreach ($layEvents as $layEvent)
        {
            $lay_eventEntity = new LayEntity($this->router,$layEvent->getId(), $layEvent->__toString(), $layEvent->getLayDate());
            $calendarEvent->addEvent($lay_eventEntity);
        }


        //trabajos culturales
        $culturalwork_query = $this->entityManager->getRepository('AppBundle:CulturalWork')
            ->createQueryBuilder('culturalwork_events')
            ->where('culturalwork_events.date BETWEEN :startDate and :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'))
            ->getQuery();
        $culturalworkEvents = $culturalwork_query->getResult();

        foreach ($culturalworkEvents as $culturalworkEvent)
        {
            $culturalwork_eventEntity = new CulturalWorkEntity($this->router,$culturalworkEvent->getId(), $culturalworkEvent->__toString(), $culturalworkEvent->getDate());
            $calendarEvent->addEvent($culturalwork_eventEntity);
        }

        //siembra y plantación
        $seedwork_query = $this->entityManager->getRepository('AppBundle:SeedWork')
            ->createQueryBuilder('seedwork_events')
            ->where('seedwork_events.real_date BETWEEN :startDate and :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'))
            ->getQuery();
        $seedworkEvents = $seedwork_query->getResult();

        foreach ($seedworkEvents as $seedworkEvent)
        {
            $seedwork_eventEntity = new SeedWorkEntity($this->router,$seedworkEvent->getId(), $seedworkEvent->__toString(), $seedworkEvent->getRealDate());
            $calendarEvent->addEvent($seedwork_eventEntity);
        }


        //eventos
        $query = $this->entityManager->getRepository('AppBundle:Event')
            ->createQueryBuilder('company_events')
            ->where('company_events.start_date BETWEEN :startDate and :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d H:i:s'))
            ->setParameter('endDate', $endDate->format('Y-m-d H:i:s'))
            ->getQuery();
        $companyEvents = $query->getResult();

        // $companyEvents and $companyEvent in this example
        // represent entities from your database, NOT instances of EventEntity
        // within this bundle.
        //
        // Create EventEntity instances and populate it's properties with data
        // from your own entities/database values.


        foreach ($companyEvents as $companyEvent)
        {
            //echo $companyEvent->getAllDayEvent();
            $eventEntity = new EventEntity($this->router, $companyEvent->getId(), $companyEvent->getTitle(),
                $companyEvent->getStartDate(), $companyEvent->getEndDate(), $companyEvent->getAllDayEvent());
            $calendarEvent->addEvent($eventEntity);
        }

/*
        //recogida
        $collect_query = $this->entityManager->getRepository('AppBundle:Collect')
            ->createQueryBuilder('collect_events')
            ->where('collect_events.collect_date BETWEEN :startDate and :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'))
            ->getQuery();
        $collectEvents = $collect_query->getResult();

        foreach ($collectEvents as $collectEvent)
        {
            $collect_eventEntity = new CollectEntity($this->router, $collectEvent->getId(), $collectEvent->__toString(), $collectEvent->getCollectDate());
            $calendarEvent->addEvent($collect_eventEntity);
        }



        //ventas
        $sale_query = $this->entityManager->getRepository('AppBundle:Sale')
            ->createQueryBuilder('collect_events')
            ->where('collect_events.sale_date BETWEEN :startDate and :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'))
            ->getQuery();
        $saleEvents = $sale_query->getResult();

        foreach ($saleEvents as $saleEvent)
        {
            $sale_eventEntity = new SaleEntity($this->router, $saleEvent->getId(), $saleEvent->__toString(), $saleEvent->getSaleDate());
            $calendarEvent->addEvent($sale_eventEntity);
        }

*/
        //compras
        $purchase_query = $this->entityManager->getRepository('AppBundle:Purchase')
            ->createQueryBuilder('collect_events')
            ->where('collect_events.purchase_date BETWEEN :startDate and :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'))
            ->getQuery();
        $purchaseEvents = $purchase_query->getResult();

        foreach ($purchaseEvents as $purchaseEvent)
        {
            $purchase_eventEntity = new PurchaseEntity($this->router, $purchaseEvent->getId(), $purchaseEvent->__toString(), $purchaseEvent->getPurchaseDate());
            $calendarEvent->addEvent($purchase_eventEntity);
        }

      /*  //regalos
        $gift_query = $this->entityManager->getRepository('AppBundle:Gift')
            ->createQueryBuilder('collect_events')
            ->where('collect_events.gift_date BETWEEN :startDate and :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'))
            ->getQuery();
        $giftEvents = $gift_query->getResult();

        foreach ($giftEvents as $giftEvent)
        {
            $gift_eventEntity = new GiftEntity($this->router, $giftEvent->getId(), $giftEvent->__toString(), $giftEvent->getGiftDate());
            $calendarEvent->addEvent($gift_eventEntity);
        }
*/
        //tareas
        $task_query = $this->entityManager->getRepository('AppBundle:Task')
            ->createQueryBuilder('collect_events')
            ->where('collect_events.expected_date BETWEEN :startDate and :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'))
            ->getQuery();
        $taskEvents = $task_query->getResult();

        foreach ($taskEvents as $taskEvent)
        {
            $task_eventEntity = new TaskEntity($this->router, $taskEvent->getId(), $taskEvent->__toString(), $taskEvent->getExpectedDate());
            $calendarEvent->addEvent($task_eventEntity);
        }

        //movimientos lotes gallinas
        $movement_query = $this->entityManager->getRepository('AppBundle:Movement')
            ->createQueryBuilder('collect_events')
            ->where('collect_events.date BETWEEN :startDate and :endDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'))
            ->getQuery();
        $movementEvents = $movement_query->getResult();

        foreach ($movementEvents as $movementEvent)
        {
            $movement_eventEntity = new MovementEntity($this->router, $movementEvent->getId(), $movementEvent->__toString(), $movementEvent->getDate());
            $calendarEvent->addEvent($movement_eventEntity);
        }

    }
}
