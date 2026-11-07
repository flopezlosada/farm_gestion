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
    /** Valores de las preguntas de sí o no del mínimo. */
    private const NO = 'no';
    private const YES = 'yes';

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
            // El mínimo se pregunta POR PASOS y no como un tipo: ¿hay mínimo?,
            // y si lo hay, ¿quieres que el pedido se confirme solo al llegar?
            // Sólo entonces se pide en qué se mide (importe o nº de socias, lo
            // único que la app sabe contar) y cuánto; si no, se explica en
            // texto y se confirma a mano. Las preguntas no se mapean: se
            // traducen al tipo de la ronda al enviar ({@see self::applyMinimum()}).
            ->add('hasMinimum', ChoiceType::class, [
                'mapped'      => false,
                'expanded'    => true,
                'label'       => '¿El productor pide un mínimo?',
                'choices'     => ['No, el pedido sale seguro' => self::NO, 'Sí' => self::YES],
                'data'        => $this->initialHasMinimum($round),
                'constraints' => [new Assert\NotNull(message: 'Di si el productor pide un mínimo.')],
            ])
            ->add('autoConfirm', ChoiceType::class, [
                'mapped'      => false,
                'expanded'    => true,
                'required'    => false,
                // Sin esto, un radio no obligatorio trae una opción «None» de
                // regalo. La obligatoriedad real depende de la pregunta anterior.
                'placeholder' => false,
                'label'       => '¿Quieres que el pedido se confirme solo al llegar al mínimo?',
                'choices'     => ['Sí' => self::YES, 'No, lo confirmamos a mano' => self::NO],
                'data'        => $this->initialAutoConfirm($round),
            ])
            ->add('minimumKind', ChoiceType::class, [
                'mapped'      => false,
                'required'    => false,
                'label'       => 'El mínimo es',
                'placeholder' => 'Elige…',
                'choices'     => [
                    'Un importe total (€)' => ConsumerGroupRound::MINIMUM_TYPE_AMOUNT,
                    'Un número de socias' => ConsumerGroupRound::MINIMUM_TYPE_PARTICIPANTS,
                ],
                'data'        => $round instanceof ConsumerGroupRound && $round->hasAutomaticMinimum() ? $round->getMinimumType() : null,
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

        return $round->hasNoMinimum() ? self::NO : self::YES;
    }

    /**
     * Qué respuesta sale marcada en «¿se confirma solo?»: sólo tiene sentido si
     * la ronda ya tiene un mínimo.
     */
    private function initialAutoConfirm(?ConsumerGroupRound $round): ?string
    {
        if (null === $round || null === $round->getId() || $round->hasNoMinimum()) {
            return null;
        }

        return $round->hasAutomaticMinimum() ? self::YES : self::NO;
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

        $type = $this->minimumTypeFrom($form, $hasMinimum);
        if (null === $type) {
            return;
        }

        // setMinimumType() ya borra el umbral si el tipo deja de usarlo.
        $round->setMinimumType($type);

        if (ConsumerGroupRound::MINIMUM_TYPE_MANUAL !== $round->getMinimumType()) {
            $round->setMinimumCondition(null);
        } elseif ('' === trim((string) $round->getMinimumCondition())) {
            $form->get('minimumCondition')->addError(new FormError('Explica cuál es el mínimo: sin eso nadie sabrá cuándo confirmarlo.'));
        }
    }

    /**
     * El tipo de mínimo según las respuestas, o null si falta una (y entonces
     * deja el error en el campo que falta).
     */
    private function minimumTypeFrom(FormInterface $form, string $hasMinimum): ?int
    {
        if (self::NO === $hasMinimum) {
            return ConsumerGroupRound::MINIMUM_TYPE_NONE;
        }

        $autoConfirm = $form->get('autoConfirm')->getData();
        if (null === $autoConfirm) {
            $form->get('autoConfirm')->addError(new FormError('Di si el pedido se confirma solo o a mano.'));

            return null;
        }

        if (self::NO === $autoConfirm) {
            return ConsumerGroupRound::MINIMUM_TYPE_MANUAL;
        }

        $kind = $form->get('minimumKind')->getData();
        if (null === $kind) {
            $form->get('minimumKind')->addError(new FormError('Elige si el mínimo es un importe o un número de socias.'));

            return null;
        }

        return (int) $kind;
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
