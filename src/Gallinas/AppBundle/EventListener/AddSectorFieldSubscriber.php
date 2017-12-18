<?php
 
namespace Gallinas\AppBundle\EventListener;
 
use Gallinas\AppBundle\Entity\Zone;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Doctrine\ORM\EntityRepository;

 
class AddSectorFieldSubscriber implements EventSubscriberInterface
{
    private $factory;
 
    public function __construct(FormFactoryInterface $factory)
    {
        $this->factory = $factory;
    }
 
    public static function getSubscribedEvents()
    {
        return array(
            FormEvents::PRE_SET_DATA => 'preSetData',
            FormEvents::PRE_SUBMIT     => 'preSubmit'
        );
    }
 
    private function addSectorForm($form, $zone)
    {
        $form->add($this->factory->createNamed('sector','entity', null, array(
            'class'         => 'AppBundle:Sector',
            'empty_value'   => 'Sector',
            'query_builder' => function (EntityRepository $repository) use ($zone) {
                $qb = $repository->createQueryBuilder('sector')
                    ->innerJoin('sector.zone', 'zone');
                if ($zone instanceof Zone) {
                    $qb->where('sector.zone = :zone')
                    ->setParameter('zone', $zone);
                } elseif (is_numeric($zone)) {
                    $qb->where('zone.id = :zone')
                    ->setParameter('zone', $zone);
                } else {
                    $qb->where('zone.name = :zone')
                    ->setParameter('zone', null);
                }
 
                return $qb;
            }
        )));
    }
 
    public function preSetData(FormEvent $event)
    {
        $data = $event->getData();
        $form = $event->getForm();
 
        if (null === $data) {
            return;
        }
 
        $zone = ($data->getSector()) ? $data->getSector()->getZone() : null ;
        $this->addSectorForm($form, $zone);
    }
 
    public function preBind(FormEvent $event)
    {
        $data = $event->getData();
        $form = $event->getForm();
 
        if (null === $data) {
            return;
        }
 
        if (array_key_exists('zone', $data)) {
            $zone = $data['zone'];
            $this->addSectorForm($form, $zone);
        }
    }
}