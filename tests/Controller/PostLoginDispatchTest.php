<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Entity\User;
use App\Entity\Worker;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El dispatcher de después del login (/post-login) manda a cada cuenta a la zona
 * que puede abrir. Se prueban las cuentas que antes caían en /panel —que exige
 * ser socix— y se comían un 403 nada más entrar (el trabajador que no es socix
 * y quien sólo tiene un rol de gestión de una sección reciente), y que quien
 * trabaja va a fichar aunque además sea de gestión.
 */
class PostLoginDispatchTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(User::class)->findAll() as $u) {
            if (str_starts_with((string) $u->getUsername(), 'post-login-test-')) {
                $u->setWorker(null);
                $em->remove($u);
            }
        }
        $em->flush();
        foreach ($em->getRepository(Worker::class)->findAll() as $w) {
            $em->remove($w);
        }
        foreach ($em->getRepository(Setting::class)->findAll() as $s) {
            $em->remove($s);
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * Cuenta de prueba con los roles almacenados dados y, si se pide, un
     * trabajador vinculado (de ahí le viene ROLE_WORKER).
     *
     * @param string   $suffix     Sufijo del username, para distinguirlas.
     * @param string[] $roles      Roles almacenados en la cuenta.
     * @param bool     $withWorker Si se le vincula una ficha de trabajador.
     * @return User
     */
    private function loginAs(string $suffix, array $roles, bool $withWorker = false): User
    {
        $user = (new User())
            ->setUsername('post-login-test-' . $suffix)
            ->setEmail('post-login-test-' . $suffix . '@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setRoles($roles);

        if ($withWorker) {
            $worker = (new Worker())
                ->setName('Trabajadora no socia')
                ->setHireDate(new \DateTimeImmutable('2026-01-01'))
                ->setAnnualVacationDays(22);
            $this->em->persist($worker);
            $user->setWorker($worker);
        }

        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * Comprueba que /post-login redirige a la ruta esperada.
     *
     * @param string $path Path al que debe apuntar la redirección.
     * @return void
     */
    private function assertDispatchesTo(string $path): void
    {
        $this->client->request('GET', '/post-login');

        $this->assertResponseRedirects($path);
    }

    public function testTrabajadorQueNoEsSocixVaAFichar(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_LABORAL, true);
        $this->loginAs('worker', [], withWorker: true);

        $this->assertDispatchesTo('/work');

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testTrabajadorQueTambienEsDeGestionVaAFichar(): void
    {
        // Fichar es lo diario; gestión queda a un toque en el conmutador.
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_LABORAL, true);
        $this->loginAs('worker-manager', ['ROLE_GESTION_CONTABILIDAD'], withWorker: true);

        $this->assertDispatchesTo('/work');
    }

    public function testAdminSinFichaDeTrabajadorNoVaAFichar(): void
    {
        // ROLE_ADMIN alcanza ROLE_WORKER por jerarquía: no debe bastar.
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_LABORAL, true);
        $this->loginAs('admin-sin-ficha', ['ROLE_ADMIN']);

        $this->assertDispatchesTo('/gestion/dashboard');
    }

    public function testTrabajadorConElModuloLaboralApagadoNoVaAFichar(): void
    {
        // Con el módulo apagado /work da 403: el dispatcher no le manda ahí.
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_LABORAL, false);
        $this->loginAs('worker-off', [], withWorker: true);

        $this->assertDispatchesTo('/panel');
    }

    public function testQuienSoloEscribeEnLaboralVaAGestion(): void
    {
        // El _EDIT alcanza la lectura por jerarquía, y la lectura abre /gestion.
        $this->loginAs('laboral-edit', ['ROLE_GESTION_LABORAL_EDIT']);

        $this->assertDispatchesTo('/gestion/dashboard');
    }

    public function testRolDeGestionDeUnaSeccionRecienteVaAGestion(): void
    {
        $this->loginAs('vencimientos', ['ROLE_GESTION_VENCIMIENTOS']);

        $this->assertDispatchesTo('/gestion/dashboard');
    }
}
