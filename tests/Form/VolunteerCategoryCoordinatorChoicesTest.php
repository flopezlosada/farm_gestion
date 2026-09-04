<?php

namespace App\Tests\Form;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Form\VolunteerCategoryType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * A quién se puede nombrar coordinadora de un área.
 *
 * Va contra la BBDD a propósito: la regla vive entera en el `query_builder` del
 * formulario, y con dobles no se ejercitaría el JOIN, que es lo único que hay.
 *
 * Importa porque marcar a alguien aquí es lo que le CONCEDE acceso al
 * voluntariado ({@see \App\Entity\User::getRoles()}). Un desplegable que ofrezca
 * de más no da error: pone a mano un permiso donde no debía haberlo.
 */
class VolunteerCategoryCoordinatorChoicesTest extends KernelTestCase
{
    /**
     * Una cuenta habilitada con socix detrás se ofrece.
     */
    public function testUnaCuentaConSocixSeOfrece(): void
    {
        $em = $this->em();
        $user = $this->makeUser($em, withPartner: true);

        $this->assertContains($user, $this->choices());
    }

    /**
     * Una cuenta SIN socix detrás no se ofrece.
     *
     * Son la cuenta `admin` —que es administración, no una persona— y tres
     * cuentas de la era vieja del sistema cuyo último acceso es de 2015 a 2017.
     * Ofrecerlas para coordinar es ofrecer a alguien que ya no está, y salían
     * además con el username en minúsculas en medio de nombres reales.
     */
    public function testUnaCuentaSinSocixNoSeOfrece(): void
    {
        $em = $this->em();
        $user = $this->makeUser($em, withPartner: false);

        $this->assertNotContains($user, $this->choices());
    }

    /**
     * Una cuenta deshabilitada no se ofrece.
     *
     * Nombrarla la dejaría con el encargo y sin poder entrar.
     */
    public function testUnaCuentaDeshabilitadaNoSeOfrece(): void
    {
        $em = $this->em();
        $user = $this->makeUser($em, withPartner: true, enabled: false);

        $this->assertNotContains($user, $this->choices());
    }

    /**
     * No hace falta ningún rol de voluntariado para aparecer.
     *
     * Antes se exigía tener marcado "Voluntariado" en Usuarias, y eso era un
     * círculo: el rol se DERIVA de coordinar, así que para poder coordinar hacía
     * falta el rol que sale de coordinar. Este test fija que ese pre-paso no
     * vuelva: una cuenta sin roles tiene que estar en la lista.
     */
    public function testNoHaceFaltaNingunRolParaAparecer(): void
    {
        $em = $this->em();
        $user = $this->makeUser($em, withPartner: true);

        $this->assertNotContains(
            'ROLE_GESTION_VOLUNTARIADO',
            $user->getRoles(),
            'el montaje del test no vale si la cuenta ya trae el rol: lo que se comprueba es que aparece SIN él'
        );
        $this->assertContains($user, $this->choices());
    }

    /**
     * Las etiquetas son nombres de persona, no correos.
     *
     * En las cuentas de socixs el username ES su correo, y un desplegable que
     * ofrece "alguien@gmail.com" no dice quién es a nadie.
     */
    public function testLasEtiquetasSonNombresYNoCorreos(): void
    {
        $em = $this->em();
        $this->makeUser($em, withPartner: true);

        foreach ($this->choiceViews() as $choice) {
            $this->assertStringNotContainsString('@', $choice->label);
        }
    }

    /**
     * Las opciones que ofrece el campo de coordinación.
     *
     * @return list<User>
     */
    private function choices(): array
    {
        return array_map(static fn ($choice): User => $choice->data, $this->choiceViews());
    }

    /**
     * @return list<ChoiceView>
     */
    private function choiceViews(): array
    {
        /** @var FormFactoryInterface $factory */
        $factory = static::getContainer()->get('form.factory');

        // Sin CSRF: el token lo guarda la sesión y aquí no hay petición.
        $form = $factory->create(VolunteerCategoryType::class, new VolunteerCategory(), [
            'csrf_protection' => false,
        ]);

        return $form->get('coordinators')->createView()->vars['choices'];
    }

    private function em(): EntityManagerInterface
    {
        self::bootKernel();

        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeUser(EntityManagerInterface $em, bool $withPartner, bool $enabled = true): User
    {
        $suffix = uniqid();

        $user = (new User())
            ->setUsername('coordchoice-' . $suffix)
            ->setEmail('coordchoice-' . $suffix . '@example.test')
            ->setPassword('x')
            ->setEnabled($enabled)
            ->setPasswordSet(true);

        if ($withPartner) {
            $partner = (new Partner())
                ->setName('Coordchoice')
                ->setSurname('Persona ' . $suffix);
            $em->persist($partner);
            $user->setPartner($partner);
        }

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
