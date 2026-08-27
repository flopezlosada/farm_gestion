<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\VolunteerCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Alta y edición de una categoría de voluntariado.
 *
 * La descripción se pinta en la ficha del socix junto a la casilla, así que
 * cuenta: quien marca "obras" tiene que saber a qué se está apuntando antes de
 * marcarla, o el aviso dirigido acaba llegando a gente que no puede ir.
 */
class VolunteerCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['placeholder' => 'p. ej. Huerta, Reparto, Obras, Oficina…'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Qué entra aquí',
                'required' => false,
                'help' => 'Se le enseña a cada socix junto a la casilla, para que sepa qué está marcando.',
                'attr' => ['rows' => 3],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Se sigue ofreciendo',
                'required' => false,
                'help' => 'Desmárcala para retirarla sin perder el histórico de tareas que la usaron.',
            ])
            ->add('coordinators', EntityType::class, [
                'label' => 'Quién coordina esta área',
                'class' => User::class,
                // Nombre de la persona, no el username: en las cuentas de socixs
                // el username es su correo, y un desplegable que ofrece
                // "aguilella.vicente@gmail.com" no dice quién es a nadie.
                'choice_label' => static fn (User $user): string => $user->getDisplayName(),
                // Sólo cuentas que pueden entrar: nombrar coordinadora a una
                // cuenta desactivada la dejaría con el rol y sin acceso.
                'query_builder' => static fn ($repository) => $repository
                    ->createQueryBuilder('u')
                    ->leftJoin('u.partner', 'p')
                    ->addSelect('p')
                    ->where('u.enabled = true')
                    ->orderBy('p.name', 'ASC')
                    ->addOrderBy('u.username', 'ASC'),
                // Casillas y no un <select multiple>: el nativo obliga a
                // ctrl+clic para elegir varias, no deja ver de un vistazo cuáles
                // están marcadas y en una caja de cuatro líneas hay que buscar
                // con scroll. Es el mismo patrón que ya usa el tipo de trabajo de
                // una tarea, y el CSS del proyecto lo pinta como pills.
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'help' => 'Podrán publicar, cerrar y pedir gente para las tareas de esta área, y sólo de ésta. Con esto les basta: no hace falta darles ningún rol aparte, se deriva solo.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VolunteerCategory::class,
        ]);
    }
}
