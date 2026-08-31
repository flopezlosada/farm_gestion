<?php

namespace App\Form;

use App\Entity\Node;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/editar un Node (sitio físico de reparto).
 * Sub-fase 8.8a (2026-05-26).
 */
class NodeType extends AbstractType
{
    /**

    /**
     * Semanas elegibles en un punto mensual. Sin "4ª" a propósito: sólo
     * coincide con "la última" en los meses de 4 semanas, y lo que
     * administración quiere decir siempre es la última.
     *
     * @var array<string,int>
     */
    private const MONTHLY_WEEK_CHOICES = [
        '1ª semana del mes'     => 1,
        '2ª semana del mes'     => 2,
        '3ª semana del mes'     => 3,
        'Última semana del mes' => Node::MONTHLY_WEEK_LAST,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'Nombre',
            ])
            ->add('deliveryWeekday', ChoiceType::class, [
                'label'   => 'Día de reparto',
                'choices' => array_flip(Node::WEEKDAY_NAMES),
            ])
            ->add('cadence', ChoiceType::class, [
                'label'   => 'Cadencia',
                'choices' => array_flip(Node::CADENCE_LABELS),
            ])
            // `required` se queda en false a propósito: el navegador lo exigiría
            // también en los puntos semanales, donde el campo no aplica. Quién
            // la necesita y con qué forma lo decide Node::validateCadenceConsistency().
            ->add('anchorDate', DateType::class, [
                'label'    => 'Fecha ancla (obligatoria si la cadencia es quincenal)',
                'widget'   => 'single_text',
                'required' => false,
                'help'     => 'Una fecha en la que este punto SÍ reparte, en su mismo día de la semana. A partir de ahí se alternan las semanas con reparto y sin él. En puntos semanales, déjala vacía.',
            ])
            // Mismo criterio que anchorDate: quién la necesita lo decide
            // Node::validateCadenceConsistency(), no el navegador.
            ->add('monthlyWeek', ChoiceType::class, [
                'label'       => 'Semana del mes (obligatoria si la cadencia es mensual)',
                'choices'     => self::MONTHLY_WEEK_CHOICES,
                'placeholder' => 'No aplica',
                'required'    => false,
                'help'        => 'La semana en que abre el punto, contada sobre su día de reparto: «2ª semana» es el 2º miércoles del mes si reparte en miércoles. «Última» sigue al último del mes, tenga 4 o 5. En puntos semanales o quincenales, déjala vacía.',
            ])
            ->add('schedule', TextType::class, [
                'label'    => 'Horario público',
                'required' => false,
                'help'     => 'Se muestra tal cual en la web pública (Hazte socix), p. ej. «Miércoles de 18:00 a 20:00». Vacío = no se publica.',
            ])
            ->add('sheetRecipients', EntityType::class, [
                'label' => 'Quién recibe el listado de este punto',
                'class' => User::class,
                // El nombre de la persona, no el username: en las cuentas de
                // socixs el username es su correo, y un desplegable que ofrece
                // "aguilella.vicente@gmail.com" no dice quién es a nadie.
                'choice_label' => static fn (User $user): string => $user->getDisplayName(),
                // Sólo cuentas del EQUIPO y habilitadas. El listado lleva nombre,
                // localidad y lo que recibe cada persona del punto, así que quien
                // lo recibe tiene que ser alguien con encargo en la casa, no
                // cualquier cuenta. Si hay que dárselo a alguien de fuera de esta
                // lista, la decisión consciente es darle su rol; para un envío
                // suelto está el ajuste general de /gestion/settings.
                //
                // LIKE sobre la columna serializada, que es como ya consulta por
                // rol UserRepository::findByRole(). Feo, pero es el formato en el
                // que Doctrine guarda el array y no vamos a cambiarlo por esto.
                'query_builder' => static fn ($repository) => $repository
                    ->createQueryBuilder('u')
                    ->leftJoin('u.partner', 'p')
                    ->addSelect('p')
                    ->where('u.enabled = true')
                    ->andWhere('u.roles LIKE :gestion OR u.roles LIKE :admin')
                    ->setParameter('gestion', '%ROLE_GESTION%')
                    ->setParameter('admin', '%"ROLE_ADMIN"%')
                    ->orderBy('p.name', 'ASC')
                    ->addOrderBy('u.username', 'ASC'),
                // Casillas y no un <select multiple>: el nativo obliga a ctrl+clic
                // para elegir varias y no deja ver de un vistazo cuáles están
                // marcadas. Mismo patrón que el selector de coordinación.
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'help' => 'Recibirán por correo el listado de este punto en cuanto cierre su plazo de cambios. Pueden ser varias personas: quien coordina, quien monta el reparto… Vacío = se manda a la dirección general de /gestion/settings.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Node::class,
        ]);
    }
}
