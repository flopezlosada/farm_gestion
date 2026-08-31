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
 *   en el payload, para no aceptar cualquier ciudad de cualquier provincia
 *   (la única ciudad extra admitida es la que el socix YA tenía guardada,
 *   para no perderla si el dato legacy no cuadra con su provincia).
 * - Grupo de validación acotado (`profile`): sólo valida nombre y apellidos.
 *   NO valida share_payment (no está en el form) ni state/city, para que un
 *   socix con datos legacy incompletos pueda guardar su dirección.
 */
class PartnerProfileType extends AbstractType
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Ciudad que el socix ya tiene guardada. Se preserva SIEMPRE como opción
        // seleccionable aunque no pertenezca a la provincia, porque el catálogo
        // legacy tiene socixs con la ciudad mal enganchada (p. ej. "Torremocha"
        // de Cáceres bajo provincia Madrid). Sin esto, el select saldría vacío,
        // la validación tumbaría el guardado y editar la dirección borraría la
        // ciudad. La corrección geográfica de esos datos es tarea aparte.
        $partner = $builder->getData();
        $currentCity = $partner instanceof Partner ? $partner->getCity() : null;

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

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($currentCity): void {
            $partner = $event->getData();
            $state = $partner instanceof Partner ? $partner->getState() : null;
            $this->addCityField($event->getForm(), $state, $currentCity);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($currentCity): void {
            $data = $event->getData();
            $state = !empty($data['state'])
                ? $this->em->getRepository(State::class)->find($data['state'])
                : null;
            $this->addCityField($event->getForm(), $state, $currentCity);
        });
    }

    /**
     * Reconstruye el campo de municipio con las ciudades de la provincia dada.
     * La ciudad actual del socix se añade siempre a las opciones aunque no
     * pertenezca a esa provincia, para no perderla al guardar.
     *
     * @param FormInterface $form        formulario padre al que se añade el campo
     * @param State|null    $state       provincia seleccionada (o null)
     * @param City|null     $currentCity ciudad ya guardada del socix, a preservar
     */
    private function addCityField(FormInterface $form, ?State $state, ?City $currentCity): void
    {
        $choices = $state === null ? [] : $state->getCities()->toArray();
        if ($currentCity !== null && !in_array($currentCity, $choices, true)) {
            $choices[] = $currentCity;
        }

        $form->add('city', EntityType::class, [
            'label' => 'Municipio',
            'class' => City::class,
            'required' => false,
            'placeholder' => $state === null && $currentCity === null ? '— Selecciona primero una provincia —' : '— Selecciona —',
            'choices' => $choices,
            'choice_label' => fn (City $c) => (string) $c,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Grupo de validación acotado: el perfil de autoservicio solo puede
        // dejar en blanco lo que de verdad gestiona el socix (nombre y
        // apellidos). NO valida share_payment (periodicidad de pago, que
        // gestiona admin y casi ningún socix tiene informada) ni state/city
        // (geografía secundaria para el reparto, que va por nodos). Así un
        // socix con datos legacy incompletos puede guardar su dirección.
        $resolver->setDefaults([
            'data_class' => Partner::class,
            'validation_groups' => ['profile'],
        ]);
    }
}
