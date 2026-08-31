<?php

namespace App\Tests\Service\Notification;

use App\Entity\City;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\State;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use App\Service\Notification\IncompleteProfileNotifier;
use App\Service\Notification\NotificationInbox;
use App\Service\Partner\PartnerProfileCompleteness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * A quién se le avisa de las fichas a medias, y cuándo se le vuelve a avisar.
 *
 * Las tres cosas que este test existe para que no se rompan:
 *
 *  1. LA JERARQUÍA DE ROLES. Quien coordina socixs suele tener ROLE_ADMIN, y de
 *     ahí a ROLE_GESTION_SOCIXS hay DOS saltos. Con una búsqueda del rol literal
 *     el aviso no le llega a nadie, y el fallo es invisible: una tarea que corre
 *     en verde sin avisar a nadie. Se usa la RoleHierarchy REAL, con la jerarquía
 *     copiada de security.yaml, y no un doble: con un doble el test pasaría
 *     aunque hicieran falta tres saltos.
 *  2. LA RENOVACIÓN. Se vuelve a avisar si el anterior SE LEYÓ y el problema
 *     sigue; si sigue sin leer, no se repite. Es literalmente lo que se pidió, y
 *     sin ello el barrido semanal apilaría un aviso más cada lunes.
 *  3. QUE AL SOCIX SÓLO SE LE PIDA LO SUYO. A quien sólo le falta el teléfono no
 *     se le avisa: no tiene dónde cambiarlo.
 */
class IncompleteProfileNotifierTest extends TestCase
{
    /**
     * La jerarquía tal como está en config/packages/security.yaml, recortada a lo
     * que importa aquí. Si allí cambia y aquí no, este test deja de proteger nada
     * — pero seguirá cazando el error de buscar el rol literal, que es el caro.
     */
    private const HIERARCHY = [
        'ROLE_ADMIN' => ['ROLE_GESTION_SOCIXS_EDIT', 'ROLE_GESTION_GRANJA'],
        'ROLE_GESTION_SOCIXS_EDIT' => ['ROLE_GESTION_SOCIXS'],
    ];

    public function testAvisaAlSocixDeLoQuePuedeRellenarElle(): void
    {
        $partner = $this->incompletePartner(['DNI']);
        $user = $this->userFor($partner);

        $escritos = [];
        $notifier = $this->notifier([$partner], [$user], $this->inbox($escritos));

        $result = $notifier->notify();

        self::assertSame(1, $result['partners']);
        self::assertSame(1, $result['incomplete']);
        self::assertCount(1, $escritos);
        self::assertSame(Notification::KIND_PROFILE_INCOMPLETE, $escritos[0]['kind']);
        self::assertSame('Faltan datos en tu ficha', $escritos[0]['title']);
        self::assertStringContainsString('DNI', (string) $escritos[0]['body']);
    }

    /**
     * Enumera en castellano y no con una lista: el aviso se lee de un tirón.
     */
    public function testEnumeraVariosDatosEnCastellano(): void
    {
        $partner = $this->incompletePartner(['DNI', 'address', 'surname']);

        $escritos = [];
        $this->notifier([$partner], [$this->userFor($partner)], $this->inbox($escritos))->notify();

        self::assertStringContainsString('apellidos, DNI y dirección', (string) $escritos[0]['body']);
    }

    /**
     * EL CASO QUE JUSTIFICA LA DISTINCIÓN: a quien sólo le falta el teléfono se le
     * cuenta en la lista de la coordinadora, pero no se le avisa a él. Sin esto
     * recibiría cada semana, para siempre, un aviso que no puede cerrar.
     */
    public function testNoAvisaAlSocixDeLoQueSoloCambiaAdministracion(): void
    {
        $partner = $this->incompletePartner(['celular']);

        $escritos = [];
        $result = $this->notifier([$partner], [$this->userFor($partner)], $this->inbox($escritos))->notify();

        self::assertSame(0, $result['partners'], 'No hay nada que él pueda rellenar.');
        self::assertSame(1, $result['incomplete'], 'Pero la ficha SÍ cuenta como a medias.');

        $kinds = array_column($escritos, 'kind');
        self::assertNotContains(Notification::KIND_PROFILE_INCOMPLETE, $kinds);
    }

    /**
     * Con el aviso anterior SIN LEER no se escribe otro: ya está diciendo lo que
     * tiene que decir, y otro igual encima sólo infla la campanita.
     */
    public function testNoRepiteElAvisoQueSigueSinLeer(): void
    {
        $partner = $this->incompletePartner(['DNI']);

        $escritos = [];
        $result = $this->notifier(
            [$partner],
            [$this->userFor($partner)],
            $this->inbox($escritos),
            hasUnread: true,
        )->notify();

        self::assertSame(0, $result['partners']);
        self::assertSame(0, $result['coordinators']);
        self::assertSame([], $escritos);
    }

    /**
     * Quien coordina socixs recibe UN aviso resumen con el número de fichas, y lo
     * recibe teniendo ROLE_ADMIN: dos saltos de jerarquía hasta
     * ROLE_GESTION_SOCIXS. Es el caso que una búsqueda del rol literal no
     * encuentra.
     */
    public function testAvisaAQuienCoordinaAunqueSuRolLoHeredeDeAdmin(): void
    {
        $partner = $this->incompletePartner(['DNI']);
        $coordinadora = (new User())->setRoles(['ROLE_ADMIN']);

        $escritos = [];
        $result = $this->notifier(
            [$partner],
            [$this->userFor($partner), $coordinadora],
            $this->inbox($escritos),
        )->notify();

        self::assertSame(1, $result['coordinators']);

        $resumen = array_values(array_filter(
            $escritos,
            static fn (array $n): bool => Notification::KIND_PARTNERS_INCOMPLETE === $n['kind']
        ));
        self::assertCount(1, $resumen, 'Uno resumen, no uno por socix.');
        self::assertStringContainsString('Una ficha', $resumen[0]['title']);
    }

    /**
     * Quien no coordina socixs no recibe el resumen, aunque tenga otros permisos de
     * gestión. Un aviso de fichas de socix en la bandeja de quien lleva la granja
     * es ruido que no puede resolver.
     */
    public function testNoAvisaAQuienNoCoordinaSocixs(): void
    {
        $partner = $this->incompletePartner(['DNI']);
        $granja = (new User())->setRoles(['ROLE_GESTION_GRANJA']);

        $escritos = [];
        $result = $this->notifier([$partner], [$granja], $this->inbox($escritos))->notify();

        self::assertSame(0, $result['coordinators']);
        self::assertSame([], $escritos, 'Ni el resumen ni nada: ese socix no tiene cuenta.');
    }

    /**
     * Con todas las fichas completas no se avisa a nadie, ni al socix ni a quien
     * coordina. Un "0 fichas a medias" semanal en la bandeja sería el aviso que
     * enseña a no mirar la campanita.
     */
    public function testConTodoCompletoNoAvisaANadie(): void
    {
        $escritos = [];
        $result = $this->notifier(
            [$this->completePartner()],
            [(new User())->setRoles(['ROLE_ADMIN'])],
            $this->inbox($escritos),
        )->notify();

        self::assertSame(['partners' => 0, 'incomplete' => 0, 'coordinators' => 0], $result);
        self::assertSame([], $escritos);
    }

    /**
     * Quien no tiene cuenta de acceso no tiene bandeja: se le cuenta en la lista de
     * la coordinadora, que es quien puede llamarle, pero no se le escribe una fila
     * que nadie podría abrir.
     */
    public function testUnSocixSinCuentaCuentaEnLaListaPeroNoRecibeNada(): void
    {
        $partner = $this->incompletePartner(['DNI']);

        $escritos = [];
        $result = $this->notifier([$partner], [], $this->inbox($escritos))->notify();

        self::assertSame(0, $result['partners']);
        self::assertSame(1, $result['incomplete']);
        self::assertSame([], $escritos);
    }

    /**
     * El notificador con sus dependencias dobladas.
     *
     * @param list<Partner> $partners   lo que devuelve el repositorio de socixs
     * @param list<User>    $users      las cuentas habilitadas
     * @param NotificationInbox $inbox  la bandeja
     * @param bool          $hasUnread  ¿todo el mundo tiene ya un aviso sin leer?
     */
    private function notifier(
        array $partners,
        array $users,
        NotificationInbox $inbox,
        bool $hasUnread = false,
    ): IncompleteProfileNotifier {
        $partnerRepository = $this->createMock(PartnerRepository::class);
        $partnerRepository->method('findActiveForProfileReview')->willReturn($partners);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findEnabled')->willReturn($users);
        // Las cuentas de socix: las que tienen Partner vinculado.
        $userRepository->method('findByPartners')->willReturn(array_values(array_filter(
            $users,
            static fn (User $u): bool => null !== $u->getPartner()
        )));

        $notifications = $this->createMock(NotificationRepository::class);
        $notifications->method('hasUnreadOfKind')->willReturn($hasUnread);

        return new IncompleteProfileNotifier(
            $partnerRepository,
            $userRepository,
            $notifications,
            $inbox,
            new PartnerProfileCompleteness(),
            new RoleHierarchy(self::HIERARCHY),
        );
    }

    /**
     * Una bandeja que apunta lo que se le pide escribir en la referencia dada.
     *
     * @param list<array{kind: string, title: string, body: ?string}> $escritos donde se apunta
     */
    private function inbox(array &$escritos): NotificationInbox
    {
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->method('record')->willReturnCallback(
            function (User $recipient, string $kind, string $title, ?string $body = null) use (&$escritos): Notification {
                $escritos[] = ['kind' => $kind, 'title' => $title, 'body' => $body];

                return new Notification($recipient, $kind, $title, $body);
            }
        );

        return $inbox;
    }

    /**
     * Una ficha activa a la que le faltan los campos indicados.
     *
     * @param list<string> $missing nombres de propiedad a vaciar (DNI, address, surname, celular…)
     *
     * @return Partner el socix
     */
    private function incompletePartner(array $missing): Partner
    {
        $partner = $this->completePartner();

        foreach ($missing as $field) {
            match ($field) {
                'surname' => $partner->setSurname(null),
                'DNI' => $partner->setDNI(null),
                'address' => $partner->setAddress(null),
                'state' => $partner->setState(null),
                'city' => $partner->setCity(null),
                'celular' => $partner->setcelular(null),
                // setemail() no admite null (la entidad legacy lo declara string),
                // y la cadena vacía es como llegan estos huecos del dump.
                'email' => $partner->setemail(''),
            };
        }

        return $partner;
    }

    /**
     * Una ficha activa con todos los datos importantes puestos y un id, que es lo
     * que necesita la indexación por socix.
     *
     * @return Partner el socix
     */
    private function completePartner(): Partner
    {
        $partner = (new Partner())
            ->setname('Eros')
            ->setSurname('García Pérez')
            ->setDNI('12345678Z')
            ->setAddress('Calle de la Huerta, 1')
            ->setState(new State())
            ->setCity(new City())
            ->setcelular('600123456')
            ->setemail('eros@csavega.local');

        // El id no tiene setter (lo pone Doctrine): se inyecta por reflexión, que
        // es lo que hace falta para que usersByPartner() indexe.
        $id = new \ReflectionProperty(Partner::class, 'id');
        $id->setValue($partner, self::$nextId++);

        return $partner;
    }

    /** Contador de ids para las fichas del test. */
    private static int $nextId = 1;

    /**
     * Una cuenta de acceso vinculada a un socix.
     *
     * @param Partner $partner el socix
     *
     * @return User la cuenta
     */
    private function userFor(Partner $partner): User
    {
        return (new User())->setPartner($partner);
    }
}
