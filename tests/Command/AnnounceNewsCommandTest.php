<?php

namespace App\Tests\Command;

use App\Entity\EmittedEffect;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\News\NewsAnnouncer;
use App\Service\News\NewsCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * El envío de las novedades de la web.
 *
 * ESTE TEST EXISTE PORQUE EL ENVÍO SE SACÓ DE LA PETICIÓN WEB. Son ~130 correos
 * y dentro de un POST el proceso se queda sin tiempo a mitad de lote; el botón
 * de administración sólo deja la tanda pedida y el trabajo de verdad pasa aquí.
 * Lo que hay que proteger es justo lo que hace que esa separación sirva:
 *
 *  - sin nada pedido, la tarea no manda nada (el caso normal: casi siempre no
 *    hay novedades que contar);
 *  - lo pedido llega por las tres vías y se marca como contado;
 *  - una segunda pasada NO repite el correo. Es lo que impide que el tick de la
 *    hora siguiente vuelva a escribir a toda la asociación si algo se torció.
 *
 * Los apuntes del ledger se limpian junto con todo lo demás: uno olvidado dejaría
 * el aviso por emitido y haría pasar al test siguiente por el motivo equivocado.
 */
class AnnounceNewsCommandTest extends KernelTestCase
{
    /** @var list<int> Ids de los socixs creados por el test. */
    private array $partners = [];

    /** @var list<int> Ids de las cuentas creadas por el test. */
    private array $users = [];

    /**
     * Deja la base como estaba: los punteros, los apuntes del ledger, los avisos
     * de la bandeja y las fichas inventadas.
     *
     * En tearDown y no al final de cada test: si una aserción falla a mitad,
     * PHPUnit lanza y una limpieza escrita en la última línea no se ejecutaría,
     * dejando basura permanente en la base de test.
     */
    protected function tearDown(): void
    {
        if (static::$booted) {
            $em = $this->em();

            foreach ($this->users as $id) {
                $user = $em->getRepository(User::class)->find($id);
                if ($user === null) {
                    continue;
                }
                foreach ($em->getRepository(Notification::class)->findBy(['recipient' => $user]) as $aviso) {
                    $em->remove($aviso);
                }
                $em->remove($user);
            }
            $em->flush();

            foreach ($this->partners as $id) {
                $partner = $em->getRepository(Partner::class)->find($id);
                if ($partner !== null) {
                    $em->remove($partner);
                }
            }

            foreach ($em->getRepository(Setting::class)->findBy(['name' => ['news.requested_through', 'news.announced_through']]) as $setting) {
                $em->remove($setting);
            }

            foreach ($em->getRepository(EmittedEffect::class)->findAll() as $effect) {
                if (str_starts_with($effect->getKind(), 'news_')) {
                    $em->remove($effect);
                }
            }

            $em->flush();
        }

        $this->partners = [];
        $this->users = [];

        parent::tearDown();
    }

    /**
     * El caso de todos los días: nadie ha pedido contar nada, así que la tarea
     * corre y se va sin hacer nada. No es un fallo.
     */
    public function testSinNadaPedidoNoMandaNada(): void
    {
        self::bootKernel();

        $tester = $this->commandTester();
        $exit = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertCount(0, $this->mailerMessages());
    }

    /**
     * El camino completo: lo pedido sale por correo, deja la copia en la bandeja
     * y queda marcado como contado para que no vuelva a salir.
     */
    public function testMandaLoPedidoYLoMarcaComoContado(): void
    {
        self::bootKernel();
        $socix = $this->crearSocixConCuenta();
        $announcer = static::getContainer()->get(NewsAnnouncer::class);
        $announcer->request();

        $esperado = static::getContainer()->get(NewsCatalog::class)->latestId();

        $tester = $this->commandTester();
        $exit = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $destinatarios = [];
        foreach ($this->mailerMessages() as $message) {
            foreach ($message->getTo() as $address) {
                $destinatarios[] = $address->getAddress();
            }
        }
        self::assertContains($socix['email'], $destinatarios, 'Al socix activo con correo tiene que llegarle.');

        $em = $this->em();
        $avisos = $em->getRepository(Notification::class)->findBy([
            'recipient' => $em->getRepository(User::class)->find($socix['user']),
            'kind' => Notification::KIND_NEWS,
        ]);
        self::assertCount(1, $avisos, 'Al socix con cuenta le tiene que quedar la copia en la bandeja.');

        self::assertSame($esperado, $this->pointer('news.announced_through'));
    }

    /**
     * La segunda pasada no repite nada. Es lo que garantiza el apunte por socix
     * del ledger: sin él, el tick de la hora siguiente escribiría otra vez a la
     * asociación entera.
     */
    public function testLaSegundaPasadaNoRepiteElCorreo(): void
    {
        self::bootKernel();
        $this->crearSocixConCuenta();
        static::getContainer()->get(NewsAnnouncer::class)->request();

        $this->commandTester()->execute(['--force' => true]);
        $primera = \count($this->mailerMessages());
        self::assertGreaterThan(0, $primera, 'La primera pasada tiene que mandar algo o el test no prueba nada.');

        $tester = $this->commandTester();
        $tester->execute(['--force' => true]);

        self::assertCount($primera, $this->mailerMessages(), 'La segunda pasada no puede mandar ni un correo más.');
    }

    /**
     * Un socix activo con correo y con cuenta de acceso: recibe por las tres
     * vías, así que sirve para comprobar las tres.
     *
     * @return array{partner: int, user: int, email: string}
     */
    private function crearSocixConCuenta(): array
    {
        $em = $this->em();
        $suffix = uniqid();
        $email = 'novedades-' . $suffix . '@example.test';

        $partner = (new Partner())
            ->setname('TESTNOVEDADES' . $suffix)
            ->setSurname('Con Cuenta')
            ->setStatus(Partner::STATUS_ACTIVO)
            ->setemail($email);
        $em->persist($partner);

        $user = (new User())
            ->setUsername('novedades-' . $suffix)
            ->setEmail($email)
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setPartner($partner);
        $em->persist($user);
        $em->flush();

        $this->partners[] = $partner->getId();
        $this->users[] = $user->getId();

        return ['partner' => $partner->getId(), 'user' => $user->getId(), 'email' => $email];
    }

    /**
     * Los correos que ha recogido el transporte de test.
     *
     * @return list<\Symfony\Component\Mime\RawMessage>
     */
    private function mailerMessages(): array
    {
        return static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getMessages();
    }

    private function pointer(string $name): int
    {
        return (int) ($this->em()->getRepository(Setting::class)->findOneBy(['name' => $name])?->getValue() ?? 0);
    }

    private function commandTester(): CommandTester
    {
        return new CommandTester((new Application(static::$kernel))->find('app:announce-news'));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
