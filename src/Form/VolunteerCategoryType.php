<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Repository\UserRepository;
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
            ->add('deliveryPrep', CheckboxType::class, [
                'label' => 'Es el montaje del reparto',
                'required' => false,
                'help' => 'Márcala sólo en el área de montar las cestas. Con ella, a cada socix se le dice en su panel quién le está preparando la cesta de esa semana en su punto de recogida — y se le avisa si no se ha apuntado nadie.',
            ])
            ->add('coordinators', EntityType::class, [
                'label' => 'Quién coordina esta área',
                'class' => User::class,
                // Nombre de la persona, no el username: en las cuentas de socixs
                // el username es su correo, y un desplegable que ofrece
                // "aguilella.vicente@gmail.com" no dice quién es a nadie.
                'choice_label' => static fn (User $user): string => $user->getDisplayName(),
                // CUALQUIER CUENTA HABILITADA, y esto se abrió a propósito.
                // Antes había que marcarle "Voluntariado" en Usuarias para que
                // apareciese aquí, y eran dos pantallas para un solo encargo.
                //
                // Ese pre-paso no protegía nada: marcar a alguien aquí es
                // justamente lo que le concede ROLE_GESTION_VOLUNTARIADO
                // ({@see \App\Entity\User::getRoles()}), así que filtrar por
                // "quien ya tiene el permiso" no evitaba ningún acceso
                // indebido — sólo obligaba a concederlo antes en otro sitio, y
                // dejaba el mismo permiso escrito en dos lugares que hay que
                // mantener a mano. Es lo que ese getRoles() dice querer evitar.
                //
                // Se sostenía además sobre un dato equivocado ("hay del orden
                // de doscientas cuentas"): las doscientas y pico son socixs,
                // pero cuentas hay 43 — el número que ya estaba bien contado en
                // {@see \App\Entity\Node::$sheetRecipients}. Con esa cifra el
                // desplegable se lee, y lo que hacía falta era poder buscar en
                // él, no recortarlo por permisos.
                //
                // Se filtran dos cosas. Las cuentas deshabilitadas, porque
                // nombrar coordinadora a una cuenta desactivada la dejaría con
                // el encargo y sin poder entrar. Y las que no tienen socix
                // detrás (INNER JOIN), que hoy son exactamente cuatro: `admin`,
                // que es la cuenta de administración y no una persona, y
                // `loreto`, `sara` y `monica`, cuyo último acceso es de 2016,
                // 2015 y 2017 — cuentas de la era vieja del sistema. Ofrecer a
                // una de ellas para coordinar es ofrecer a alguien que ya no
                // está, y salían encima con el username en minúsculas en medio
                // de una lista de nombres reales.
                //
                // El javadoc de VolunteerCategory::$coordinators defiende que la
                // relación cuelgue de User porque "no toda persona que coordina
                // algo es socia". Sigue siendo verdad como diseño, pero hoy no
                // hay ni un caso: cero cuentas vinculadas a un Worker. El día
                // que exista, este INNER JOIN es la línea que hay que revisar.
                'query_builder' => static fn (UserRepository $repository) => $repository
                    ->createQueryBuilder('u')
                    // Fetch join: getDisplayName() pregunta por el socix una vez
                    // por opción, y sin traerlo aquí son cuarenta consultas.
                    ->innerJoin('u.partner', 'p')
                    ->addSelect('p')
                    ->where('u.enabled = true')
                    ->orderBy('p.name', 'ASC')
                    ->addOrderBy('p.surname', 'ASC'),
                // Casillas y no un <select multiple>: el nativo obliga a
                // ctrl+clic para elegir varias, no deja ver de un vistazo cuáles
                // están marcadas y en una caja de cuatro líneas hay que buscar
                // con scroll. Es el mismo patrón que ya usa el tipo de trabajo de
                // una tarea, y el CSS del proyecto lo pinta como pills.
                //
                // Con las 43 cuentas son unas quince filas de pills, así que la
                // plantilla les pone un buscador por encima
                // (`data-csa-check-filter`). Se filtra lo ya pintado en vez de
                // cambiar a un desplegable con búsqueda porque lo que aporta la
                // casilla es ver de un golpe quién está marcado, y un
                // desplegable esconde justo eso.
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'help' => 'Marcar a alguien aquí le da acceso al voluntariado de esta área, y sólo de ésta: podrá publicar tareas, cerrarlas y pedir gente. No hace falta darle ningún permiso aparte en Usuarias.',
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
