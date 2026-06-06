<?php

namespace App\Form;

use App\Entity\City;
use App\Entity\Partner;
use App\Entity\State;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form que un socix usa para editar sus propios datos desde /panel/perfil.
 *
 * Es un subset reducido de PartnerType:
 * - Edita identidad y contacto (excepto email y teléfono).
 * - NO toca: forma de pago, grupo de recogida, fecha de inscripción,
 *   consumo de cárnicos, ficha de socix. Eso lo gestiona admin.
 * - Email y teléfono no aparecen como campos editables: el email es la
 *   credencial de acceso (User.email) y el teléfono es factor de recovery
 *   del magic-link en /login/first-access. Ambos se muestran en sólo
 *   lectura en el template, con CTA a admin para cambiarlos.
 * - Provincia y municipio son selects dependientes: el HTML inicial sólo
 *   trae las ciudades de la provincia ya seleccionada (o ninguna); el JS
 *   del template repuebla via /panel/perfil/municipios/{state}. La
 *   validación POST refiltra las choices del city con la state que viene
 *   en el payload, para no aceptar cualquier ciudad de cualquier provincia.
 */
class PartnerProfileType extends AbstractType
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nombre'])
            ->add('surname', TextType::class, ['label' => 'Apellidos'])
            ->add('DNI', TextType::class, ['label' => 'DNI', 'required' => false])
            ->add('address', TextType::class, ['label' => 'Dirección', 'required' => false])
            ->add('state', EntityType::class, [
                'label' => 'Provincia',
                'class' => State::class,
                'required' => false,
                'placeholder' => '— Selecciona —',
                'choice_label' => fn (State $s) => (string) $s,
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $partner = $event->getData();
            $state = $partner instanceof Partner ? $partner->getState() : null;
            $this->addCityField($event->getForm(), $state);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            $state = !empty($data['state'])
                ? $this->em->getRepository(State::class)->find($data['state'])
                : null;
            $this->addCityField($event->getForm(), $state);
        });
    }

    private function addCityField(FormInterface $form, ?State $state): void
    {
        $form->add('city', EntityType::class, [
            'label' => 'Municipio',
            'class' => City::class,
            'required' => false,
            'placeholder' => $state === null ? '— Selecciona primero una provincia —' : '— Selecciona —',
            'choices' => $state === null ? [] : $state->getCities(),
            'choice_label' => fn (City $c) => (string) $c,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Partner::class]);
    }
}
