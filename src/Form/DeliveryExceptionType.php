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
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/editar una excepción de calendario de reparto.
 *
 * El alcance manda sobre el efecto, según la operativa real del colectivo:
 *   - sin nodo (todos los nodos) = cierre general (vacaciones del equipo, no
 *     se recoge para nadie). No hay fecha de traslado.
 *   - con nodo = traslado del reparto de ESE nodo a otra fecha (un festivo
 *     que cae en su día). Exige fecha destino.
 * Un cierre de un solo nodo no se ha dado nunca en la práctica y la UI no lo
 * ofrece; el modelo lo seguiría soportando si hiciera falta puntualmente.
 *
 * Por eso el form no expone un selector de "efecto": lo deriva el listener
 * POST_SUBMIT a partir del nodo. La selección de ciclo y nodo se presenta en
 * la plantilla como tarjetas con las fechas físicas reales de cada nodo
 * (miércoles para Madrid, viernes para Torremocha); aquí `basket` y `node`
 * son los campos que esas tarjetas rellenan.
 *
 * Sub-fase 8.8d (2026-05-27); flujo alcance-primero reescrito 2026-06-02.
 */
class DeliveryExceptionType extends AbstractType
{
    /** Cuántos ciclos futuros ofrecer en el desplegable (~año y pico). */
    private const FUTURE_CYCLES = 70;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('node', EntityType::class, [
                'class' => Node::class,
                'label' => 'Nodo',
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Todos los nodos (cierre general)',
            ])
            ->add('basket', EntityType::class, [
                'class' => Basket::class,
                'label' => 'Ciclo (semana)',
                'choice_label' => static fn (Basket $basket): string => 'Viernes ' . $basket->getDate()->format('d/m/Y'),
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('b')
                    ->where('b.date >= :today')
                    ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
                    ->orderBy('b.date', 'ASC')
                    ->setMaxResults(self::FUTURE_CYCLES),
            ])
            ->add('shiftedDate', DateType::class, [
                'label' => 'Nuevo día de reparto',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Motivo / notas',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
        ;

        // Deriva el efecto del alcance: sin nodo es un cierre (no hay fecha
        // destino); con nodo es un traslado, que exige fecha.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $exception = $event->getData();
            if (!$exception instanceof DeliveryException) {
                return;
            }

            if ($exception->getNode() === null) {
                $exception->setShiftedDate(null);
            } elseif ($exception->getShiftedDate() === null) {
                $event->getForm()->get('shiftedDate')->addError(
                    new FormError('Indica el día al que se traslada el reparto.')
                );
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeliveryException::class,
        ]);
    }
}
