<?php

namespace App\Form;

use App\Entity\Basket;
use App\Entity\DeliveryException;
use App\Entity\Node;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/editar una excepción de calendario de reparto.
 *
 * El ciclo se elige entre los Basket futuros (no los miles pre-sembrados:
 * sólo los próximos, suficientes para planificar el año). El nodo es
 * opcional: vacío = la excepción aplica a todos los nodos (cierre general).
 * shiftedDate vacío = no hay reparto ese ciclo; con fecha = se traslada.
 *
 * Sub-fase 8.8d (2026-05-27).
 */
class DeliveryExceptionType extends AbstractType
{
    /** Cuántos ciclos futuros ofrecer en el desplegable (~año y pico). */
    private const FUTURE_CYCLES = 70;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('basket', EntityType::class, [
                'class' => Basket::class,
                'label' => 'Ciclo (semana)',
                'choice_label' => static fn (Basket $basket): string => 'Viernes ' . $basket->getDate()->format('d/m/Y'),
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('b')
                    ->where('b.date >= :today')
                    ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
                    ->orderBy('b.date', 'ASC')
                    ->setMaxResults(self::FUTURE_CYCLES),
                'help' => 'El ciclo semanal afectado. Para nodos que reparten otro día (p.ej. miércoles), elige el viernes de esa semana: el sistema deriva el día real.',
            ])
            ->add('node', EntityType::class, [
                'class' => Node::class,
                'label' => 'Nodo',
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Todos los nodos (cierre general)',
                'help' => 'Déjalo vacío para un cierre que afecta a todos los nodos (Navidad, agosto…). Elige uno para un festivo que solo afecta a ese nodo.',
            ])
            ->add('shiftedDate', DateType::class, [
                'label' => 'Trasladar a',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Déjalo vacío si ese ciclo NO hay reparto. Pon una fecha si el reparto se mueve a otro día (típicamente el día hábil anterior).',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Motivo / notas',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeliveryException::class,
        ]);
    }
}
