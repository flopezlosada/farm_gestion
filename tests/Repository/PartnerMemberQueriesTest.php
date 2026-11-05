<?php

namespace App\Tests\Repository;

use App\Entity\Partner;
use App\Entity\VolunteerCategory;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Qué consultas cuentan a quien recibe cesta sin ser socia y cuáles no.
 *
 * Contra la BBDD porque el filtro vive en el DQL. Autocontenido: crea sus fichas
 * y comprueba pertenencia, no conteos, salvo donde el conteo ES lo que se prueba
 * (y entonces compara con y sin la ficha).
 */
class PartnerMemberQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PartnerRepository $partners;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->partners = $this->em->getRepository(Partner::class);
    }

    /**
     * Una ficha en ACTIVO, con correo para que entre en las listas de envío.
     */
    private function partner(string $name, bool $member): Partner
    {
        $partner = (new Partner())
            ->setName($name)
            ->setEmail(strtolower(str_replace(' ', '.', $name)) . '.' . uniqid() . '@example.org')
            ->setMember($member);
        $this->em->persist($partner);

        return $partner;
    }

    /**
     * Lo que va a todo el mundo (novedades, el responsable del listado) la sigue
     * contando: recibe cesta y usa el panel como cualquiera.
     */
    public function testLasListasGeneralesLaIncluyen(): void
    {
        $worker = $this->partner('Miembros Trabajadora', false);
        $this->em->flush();

        $this->assertContains($worker, $this->partners->findActive());
        $this->assertContains($worker, $this->partners->findActiveWithEmail());
    }

    public function testLasListasDeSociasLaDejanFuera(): void
    {
        $member = $this->partner('Miembros Socia', true);
        $worker = $this->partner('Miembros Trabajadora2', false);
        $this->em->flush();

        foreach (['findActiveMembers', 'findActiveMembersWithEmail', 'findMembers', 'findVolunteeringAudience', 'findActiveWithoutVolunteerPreferences', 'findActiveForProfileReview'] as $method) {
            $found = $this->partners->{$method}();
            $this->assertContains($member, $found, $method);
            $this->assertNotContains($worker, $found, $method);
        }
    }

    public function testElVoluntariadoPorAreaLaDejaFuera(): void
    {
        $category = (new VolunteerCategory())->setName('Miembros Categoría');
        $this->em->persist($category);
        $member = $this->partner('Miembros Socia3', true)->addVolunteerCategory($category);
        $worker = $this->partner('Miembros Trabajadora3', false)->addVolunteerCategory($category);
        $this->em->flush();

        $found = $this->partners->findActiveMatchingVolunteerCategories([$category]);

        $this->assertContains($member, $found);
        $this->assertNotContains($worker, $found);

        $pool = $this->partners->volunteeringPoolQb('all')->getQuery()->getResult();
        $this->assertNotContains($worker, $pool);
    }

    /**
     * El número de socias de la portada no sube por darle cesta a una trabajadora.
     */
    public function testNoCuentaComoSocia(): void
    {
        $before = $this->partners->countActive();
        $this->partner('Miembros Trabajadora4', false);
        $this->em->flush();

        $this->assertSame($before, $this->partners->countActive());

        $this->partner('Miembros Socia4', true);
        $this->em->flush();

        $this->assertSame($before + 1, $this->partners->countActive());
    }
}
