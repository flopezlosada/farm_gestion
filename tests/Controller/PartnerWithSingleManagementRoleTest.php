<?php

namespace App\Tests\Controller;

use App\Entity\Partner;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Un socix con UN solo permiso de gestión (además de su panel) tiene que llegar
 * a gestión: verla en el conmutador de áreas y encontrar su sección en el menú.
 * Antes de la lista única de roles de gestión, el conmutador llevaba una copia
 * a la que le faltaban secciones, y una socia con sólo grupo de consumo no veía
 * «Gestión» hasta que se le daba también reparto.
 */
class PartnerWithSingleManagementRoleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $settings = static::getContainer()->get(AppSettings::class);
        $settings->setBool(AppSettings::FEATURE_LABORAL, true);
        $settings->setBool(AppSettings::FEATURE_GRUPO_CONSUMO, true);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(User::class)->findAll() as $u) {
            if (str_starts_with((string) $u->getUsername(), 'single-mgmt-test-')) {
                $partner = $u->getPartner();
                $em->remove($u);
                if ($partner !== null) {
                    $em->remove($partner);
                }
            }
        }
        foreach ($em->getRepository(Setting::class)->findAll() as $s) {
            $em->remove($s);
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function singleRoles(): iterable
    {
        yield 'laboral' => ['ROLE_GESTION_LABORAL', 'Personal'];
        yield 'grupo de consumo' => ['ROLE_GESTION_GRUPO_CONSUMO', 'Grupo de consumo'];
    }

    /**
     * @dataProvider singleRoles
     */
    public function testPartnerWithOnlyThisRoleReachesManagement(string $role, string $menuGroup): void
    {
        $partner = (new Partner())->setname('Socia')->setSurname('Gestora');
        $user = (new User())
            ->setUsername('single-mgmt-test-' . strtolower(substr($role, 13)))
            ->setEmail('single-mgmt-test-' . strtolower(substr($role, 13)) . '@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setRoles(['ROLE_PARTNER', $role])
            ->setPartner($partner);
        $this->em->persist($partner);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/gestion/dashboard');
        $this->assertResponseIsSuccessful();

        // El conmutador sólo se pinta con dos áreas o más: Gestión (activa) y Mi panel.
        $this->assertCount(1, $crawler->filter('.csa-areaswitch__item--active:contains("Gestión")'), 'Debe ver «Gestión» en el conmutador.');
        $this->assertCount(1, $crawler->filter('.csa-areaswitch a[href="/panel"]'), 'Y poder volver a su panel.');

        $heads = $crawler->filter('.csa-navgroup__head')->each(static fn ($n) => trim($n->text()));
        $this->assertContains($menuGroup, $heads, 'Su sección tiene que salir en el menú de gestión.');
    }
}
