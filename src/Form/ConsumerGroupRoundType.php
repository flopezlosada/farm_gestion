<?php

namespace App\Form;

use App\Entity\ConsumerGroupRound;
use App\Entity\Producer;
use App\Repository\ProducerRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form de la CABECERA de una ronda: productor (del catálogo) y datos de la ronda.
 * Los productos de la ronda ({@see \App\Entity\ConsumerGroupRoundItem}) NO se editan
 * aquí, sino en la pantalla de "productos de la ronda" (se siembran del catálogo del
 * productor al crearla).
 *
 * Opción `lock_producer` (true en edición): el productor no se cambia una vez creada
 * la ronda, porque sus productos cuelgan de ese catálogo.
 */
class ConsumerGroupRoundType extends AbstractType
{
    /** Valores de la pregunta «¿pide mínimo?». */
    private const HAS_MINIMUM_NO = 'no';
    private const HAS_MINIMUM_YES = 'yes';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $round = $options['data'] ?? null;

        $builder
            ->add('title', TextType::class, [
                'label' => 'Título',
                'attr'  => ['placeholder' => 'Fruta de temporada — julio'],
            ])
            ->add('producer', EntityType::class, [
                'label'         => 'Productor',
                'class'         => Producer::class,
                'choice_label'  => 'name',
                'placeholder'   => 'Elige un productor…',
                'disabled'      => $options['lock_producer'],
                'query_builder' => static fn (ProducerRepository $r) => $r->createQueryBuilder('p')
                    ->where('p.active = true')
                    ->orderBy('p.name', 'ASC'),
            ])
            ->add('ordersCloseAt', DateType::class, [
                'label'  => 'Cierre de apuntes',
                'widget' => 'single_text',
                'html5'  => true,
            ])
            ->add('deliveryDate', DateType::class, [
                'label'    => 'Fecha de entrega (se reparte con la cesta)',
                'widget'   => 'single_text',
                'html5'    => true,
                'required' => false,
                'help'     => 'Si todavía no se sabe, se puede añadir más adelante.',
            ])
            // El mínimo se pregunta en DOS PASOS y no como un tipo: primero si
            // lo hay, luego en qué se mide. La comisión sabe cómo es el mínimo
            // del productor («150 €», «50 kg de aceitunas»), no si la app lo
            // puede automatizar; eso lo deduce la pantalla de la unidad y se lo
            // cuenta. Ninguno de los dos campos se mapea: se traducen al tipo
            // de la ronda al enviar ({@see self::applyMinimum()}).
            ->add('hasMinimum', ChoiceType::class, [
                'mapped'      => false,
                'expanded'    => true,
                'label'       => '¿El productor pide un mínimo?',
                'choices'     => ['No, el pedido sale seguro' => self::HAS_MINIMUM_NO, 'Sí' => self::HAS_MINIMUM_YES],
                'data'        => $this->initialHasMinimum($round),
                'constraints' => [new Assert\NotNull(message: 'Di si el productor pide un mínimo.')],
            ])
            ->add('minimumKind', ChoiceType::class, [
                'mapped'   => false,
                'expanded' => true,
                'required' => false,
                // Sin esto, un radio no obligatorio trae una opción «None» de
                // regalo. La obligatoriedad real depende de la otra pregunta.
                'placeholder' => false,
                'label'    => '¿En qué se mide?',
                'choices'  => [
                    'Euros' => ConsumerGroupRound::MINIMUM_TYPE_AMOUNT,
                    'Socias' => ConsumerGroupRound::MINIMUM_TYPE_PARTICIPANTS,
                    'Otra cosa' => ConsumerGroupRound::MINIMUM_TYPE_MANUAL,
                ],
                'data'     => $round instanceof ConsumerGroupRound && !$round->hasNoMinimum() ? $round->getMinimumType() : null,
            ])
            ->add('minimumValue', NumberType::class, [
                'label'    => '¿Cuánto?',
                'required' => false,
                'html5'    => true,
                'scale'    => 2,
                'attr'     => ['step' => '0.01', 'min' => '0', 'placeholder' => 'p. ej. 150'],
            ])
            ->add('minimumCondition', TextType::class, [
                'label'    => '¿Cuál es el mínimo?',
                'required' => false,
                'attr'     => ['placeholder' => 'p. ej. 50 kg de aceitunas entre todas'],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Descripción para las socias',
                'required' => false,
                'attr'     => ['rows' => 6],
            ])
            ->add('providerNote', TextareaType::class, [
                'label'    => 'Nota para el productor (interna)',
                'required' => false,
                'attr'     => ['rows' => 3],
            ]);

        // Antes que la validación (prioridad 10 frente a 0), para que valide
        // lo que de verdad se va a guardar.
        $builder->addEventListener(FormEvents::POST_SUBMIT, fn (FormEvent $event) => $this->applyMinimum($event->getForm()), 10);
    }

    /**
     * Qué respuesta sale marcada en «¿pide mínimo?». En un pedido nuevo,
     * NINGUNA: un «no» por defecto confirmaría el pedido al abrirlo sin que
     * nadie lo haya decidido.
     */
    private function initialHasMinimum(?ConsumerGroupRound $round): ?string
    {
        if (null === $round || null === $round->getId()) {
            return null;
        }

        return $round->hasNoMinimum() ? self::HAS_MINIMUM_NO : self::HAS_MINIMUM_YES;
    }

    /**
     * Traduce las dos preguntas al tipo de mínimo de la ronda y limpia lo que
     * ya no aplica.
     *
     * Los campos escondidos en pantalla viajan igual en el envío: sin esta
     * limpieza, pasar de «euros» a «sin mínimo» dejaría guardado un umbral que
     * nadie vigila, o una nota de un mínimo que ya no existe.
     */
    private function applyMinimum(FormInterface $form): void
    {
        $round = $form->getData();
        $hasMinimum = $form->get('hasMinimum')->getData();
        if (!$round instanceof ConsumerGroupRound || null === $hasMinimum) {
            return;
        }

        $kind = $form->get('minimumKind')->getData();
        if (self::HAS_MINIMUM_YES === $hasMinimum && null === $kind) {
            $form->get('minimumKind')->addError(new FormError('Elige en qué se mide el mínimo.'));

            return;
        }

        // setMinimumType() ya borra el umbral si el tipo deja de usarlo.
        $round->setMinimumType(self::HAS_MINIMUM_NO === $hasMinimum ? ConsumerGroupRound::MINIMUM_TYPE_NONE : (int) $kind);

        if (ConsumerGroupRound::MINIMUM_TYPE_MANUAL !== $round->getMinimumType()) {
            $round->setMinimumCondition(null);
        } elseif ('' === trim((string) $round->getMinimumCondition())) {
            $form->get('minimumCondition')->addError(new FormError('Explica cuál es el mínimo: sin eso nadie sabrá cuándo confirmarlo.'));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => ConsumerGroupRound::class,
            'lock_producer'  => false,
        ]);
        $resolver->setAllowedTypes('lock_producer', 'bool');
    }
}
