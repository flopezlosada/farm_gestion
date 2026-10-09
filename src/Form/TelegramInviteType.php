<?php

namespace App\Form;

use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Elegir a quién invitar al bot de Telegram: cualquier cuenta activa de la web.
 */
class TelegramInviteType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder Constructor del formulario.
     * @param array<string, mixed> $options Opciones.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('user', EntityType::class, [
            'class' => User::class,
            'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('u')
                ->leftJoin('u.partner', 'p')->addSelect('p')
                ->andWhere('u.enabled = true')
                ->orderBy('u.username', 'ASC'),
            'choice_label' => static fn (User $u): string => $u->getDisplayName() . ($u->getEmail() ? ' · ' . $u->getEmail() : ''),
            'placeholder' => 'Elige a la persona',
            'constraints' => [new NotNull(message: 'Elige a quién invitar.')],
        ]);
    }
}
