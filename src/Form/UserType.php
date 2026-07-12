<?php

namespace App\Form;

use App\Entity\Partner;
use App\Entity\User;
use App\Validator\StrongPassword;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form de alta/edición de cuentas del equipo.
 *
 * Los roles NO se exponen como una lista plana de casillas (no escala: cada
 * sección que parte su rol en lectura/escritura metería dos casillas que
 * encima se solapan). En su lugar, una sección = un selector de NIVEL de
 * acceso. El catálogo de secciones vive en {@see self::SECTIONS}: añadir una
 * sección (o partir su rol) es tocar solo ese array, ni el resto del form ni
 * la plantilla. La traducción nivel → strings de rol (y la inversa, para
 * precargar el form en edición) la hacen los listeners de eventos.
 */
class UserType extends AbstractType
{
    /** Niveles de acceso por sección (valores internos del selector). */
    public const LEVEL_NONE = 'none';
    public const LEVEL_READ = 'read';
    public const LEVEL_EDIT = 'edit';

    /**
     * Catálogo de secciones gestionables. Cada una declara su rol de lectura
     * y, si tiene el modelo lectura/escritura partido, su rol de edición
     * (`edit` = null mientras la sección use un único rol = acceso total).
     *
     * ROLE_ADMIN NO va aquí: no es una sección, es la casilla de "acceso a
     * todo". ROLE_PARTNER tampoco: lo deriva {@see User::getRoles()} del
     * vínculo con un Partner. ROLE_USER se añade solo, también en getRoles().
     */
    public const SECTIONS = [
        'granja'    => ['label' => 'Granja',             'read' => 'ROLE_GESTION_GRANJA',    'edit' => null],
        'socixs'    => ['label' => 'Socixs',             'read' => 'ROLE_GESTION_SOCIXS',    'edit' => 'ROLE_GESTION_SOCIXS_EDIT'],
        'reparto'   => ['label' => 'Reparto',            'read' => 'ROLE_GESTION_REPARTO',   'edit' => null],
        'blog'      => ['label' => 'Blog y comunicación', 'read' => 'ROLE_BLOG',             'edit' => null],
        'encuestas' => ['label' => 'Encuestas',          'read' => 'ROLE_GESTION_ENCUESTAS', 'edit' => 'ROLE_GESTION_ENCUESTAS_EDIT'],
        'albergue'  => ['label' => 'Albergue',           'read' => 'ROLE_GESTION_ALBERGUE',  'edit' => 'ROLE_GESTION_ALBERGUE_EDIT'],
        'lar'       => ['label' => 'LAR',                'read' => 'ROLE_GESTION_LAR',       'edit' => 'ROLE_GESTION_LAR_EDIT'],
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, ['label' => 'Nombre de usuaria'])
            ->add('email', EmailType::class, ['label' => 'Email']);

        // El campo de contraseña solo aparece en alta (require_password=true).
        // En edición admin NO se expone: el reseteo lo hace el propio usuario
        // por el flujo de magic-link en /login/forgot, no admin a mano.
        if ($options['require_password']) {
            $builder->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'Contraseña'],
                'second_options' => ['label' => 'Repetir contraseña'],
                'invalid_message' => 'Las contraseñas no coinciden.',
                'required' => true,
                'constraints' => [new StrongPassword()],
            ]);
        }

        // Acceso "a todo" + un selector de nivel por sección. Campos NO
        // mapeados: la propiedad `roles` del User la componen los listeners a
        // partir de estos valores. Se añaden aquí para que el form tenga
        // estructura estable; el PRE_SET_DATA los RE-AÑADE con su `data` para
        // precargar el valor actual (ver nota allí).
        $builder->add('is_admin', CheckboxType::class, self::adminFieldOptions());
        foreach (self::SECTIONS as $key => $section) {
            $builder->add('access_' . $key, ChoiceType::class, self::accessFieldOptions($section));
        }

        // El vínculo con socix sólo se establece al crear la cuenta. Es 1:1 y
        // permanente (una cuenta es de una persona concreta), así que en
        // edición NO se expone: se muestra como solo lectura en la plantilla.
        if ($options['include_partner']) {
            $builder->add('partner', EntityType::class, [
                'label' => 'Socix vinculadx',
                'class' => Partner::class,
                'choice_label' => fn (Partner $p) => $p->getName() . ' ' . $p->getSurname(),
                'required' => false,
                'placeholder' => '— sin vínculo —',
                'help' => 'Solo necesario si esta cuenta corresponde a una persona socia y va a usar el panel /panel.',
            ]);
        }

        // Precarga: deriva el nivel de cada sección (y la casilla admin) de los
        // roles ya almacenados en el User que se está editando.
        //
        // Se RE-AÑADEN los campos con su opción `data` en vez de hacer
        // `$form->get(...)->setData(...)`: en campos NO mapeados, el setData
        // dentro del PRE_SET_DATA del padre NO se refleja en la vista (el radio
        // no sale `checked`). Re-añadir con `data` es el patrón idiomático que
        // sí precarga el valor inicial.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $user = $event->getData();
            $roles = $user instanceof User ? $user->getRoles() : [];
            $form = $event->getForm();

            $form->add('is_admin', CheckboxType::class, array_merge(
                self::adminFieldOptions(),
                ['data' => \in_array('ROLE_ADMIN', $roles, true)],
            ));

            foreach (self::SECTIONS as $key => $section) {
                $form->add('access_' . $key, ChoiceType::class, array_merge(
                    self::accessFieldOptions($section),
                    ['data' => self::levelFromRoles($section, $roles)],
                ));
            }
        });

        // Volcado: traduce los niveles elegidos a strings de rol y los escribe
        // en el User. Admin marcado = "todo" y gana sobre las secciones.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $user = $event->getData();
            if (!$user instanceof User) {
                return;
            }
            $user->setRoles(self::composeRoles($event->getForm()));
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => true,
            'include_partner' => true,
        ]);
        $resolver->setAllowedTypes('require_password', 'bool');
        $resolver->setAllowedTypes('include_partner', 'bool');
    }

    /** Opciones del campo no mapeado de la casilla "Administrador". */
    private static function adminFieldOptions(): array
    {
        return [
            'mapped' => false,
            'required' => false,
            'label' => 'Administrador (acceso a todo)',
        ];
    }

    /**
     * Opciones del selector de nivel de una sección. Radios (no <select>): la
     * plantilla los pinta como control segmentado.
     *
     * @param array{label: string, read: string, edit: string|null} $section
     */
    private static function accessFieldOptions(array $section): array
    {
        return [
            'mapped' => false,
            'required' => true,
            'label' => $section['label'],
            'choices' => self::levelChoices($section),
            'expanded' => true,
            'multiple' => false,
        ];
    }

    /**
     * Opciones del selector de una sección. Sin rol de edición, solo ofrece
     * acceso completo; con él, distingue lectura de edición.
     *
     * @param array{label: string, read: string, edit: string|null} $section
     * @return array<string, string> etiqueta visible => valor de nivel
     */
    private static function levelChoices(array $section): array
    {
        if ($section['edit'] === null) {
            // "Acceso completo" = lectura + escritura (el rol único de la
            // sección), así que usa el nivel de EDICIÓN: misma semántica y mismo
            // color verde que la edición de las secciones partidas.
            return ['Sin acceso' => self::LEVEL_NONE, 'Acceso completo' => self::LEVEL_EDIT];
        }

        return [
            'Sin acceso' => self::LEVEL_NONE,
            'Lectura' => self::LEVEL_READ,
            'Edición' => self::LEVEL_EDIT,
        ];
    }

    /**
     * Nivel actual de una sección a partir de los roles almacenados. La
     * edición manda sobre la lectura (escritura incluye lectura).
     *
     * @param array{label: string, read: string, edit: string|null} $section
     * @param string[] $roles
     */
    private static function levelFromRoles(array $section, array $roles): string
    {
        if ($section['edit'] !== null && \in_array($section['edit'], $roles, true)) {
            return self::LEVEL_EDIT;
        }
        if (\in_array($section['read'], $roles, true)) {
            // Sin split, tener el rol = acceso completo (nivel edición); con
            // split, el rol base es solo lectura.
            return $section['edit'] === null ? self::LEVEL_EDIT : self::LEVEL_READ;
        }

        return self::LEVEL_NONE;
    }

    /**
     * Compone el array de roles a guardar a partir del form enviado.
     *
     * @return string[]
     */
    private static function composeRoles(FormInterface $form): array
    {
        $roles = [];

        if ($form->get('is_admin')->getData()) {
            $roles[] = 'ROLE_ADMIN';
        } else {
            foreach (self::SECTIONS as $key => $section) {
                $level = $form->get('access_' . $key)->getData();
                if ($level === self::LEVEL_EDIT) {
                    // Sin split, "acceso completo" cae al rol único de la sección.
                    $roles[] = $section['edit'] ?? $section['read'];
                } elseif ($level === self::LEVEL_READ) {
                    $roles[] = $section['read'];
                }
            }
        }

        // Preservar los roles que este formulario NO gestiona (p.ej.
        // ROLE_SUPER_ADMIN del admin, ROLE_GUEST, ROLE_COMPOST). Sin esto, al
        // guardar la ficha se reconstruiría el array desde cero y se perderían.
        // ROLE_USER, ROLE_PARTNER y ROLE_WORKER se derivan en User::getRoles()
        // (de las relaciones ORM, no se almacenan), así que no se preservan a
        // mano: persistirlos duplicaría el rol en la columna.
        $user = $form->getData();
        if ($user instanceof User) {
            $managed = self::managedRoles();
            $derived = ['ROLE_USER', 'ROLE_PARTNER', 'ROLE_WORKER'];
            foreach ($user->getRoles() as $role) {
                if (!\in_array($role, $managed, true) && !\in_array($role, $derived, true)) {
                    $roles[] = $role;
                }
            }
        }

        // ROLE_PARTNER NO se añade aquí: User::getRoles() lo deriva del vínculo
        // con un Partner, así que el acceso al panel lo determina ese vínculo.
        return array_values(array_unique($roles));
    }

    /**
     * Roles que este formulario controla (puede asignar o quitar): admin y los
     * de cada sección. Todo lo demás que tenga el usuario se respeta.
     *
     * @return string[]
     */
    private static function managedRoles(): array
    {
        $managed = ['ROLE_ADMIN'];
        foreach (self::SECTIONS as $section) {
            $managed[] = $section['read'];
            if ($section['edit'] !== null) {
                $managed[] = $section['edit'];
            }
        }

        return $managed;
    }
}
