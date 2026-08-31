<?php

namespace App\Tests\Repository;

use App\Entity\Partner;
use App\Entity\VolunteerCategory;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A quién devuelven las tres consultas de audiencia del voluntariado.
 *
 * Va contra la BBDD y no con dobles porque la regla que importa vive en el SQL:
 * el filtro de "no me avises de voluntariado" está en las tres consultas
 * precisamente para que ninguna vía futura pueda saltárselo por descuido, y un
 * test con mocks no ejercitaría ese filtro.
 *
 * Autocontenido: crea sus propios socixs y no depende del estado de db_test, así
 * que comprueba pertenencia y no conteos.
 */
class PartnerVolunteeringAudienceTest extends KernelTestCase
{
    /**
     * Quien ha pedido que no se le avise queda fuera del aviso general.
     *
     * Es el caso importante de los tres. El aviso general lo lanza una persona a
     * mano desde gestión, y es justo donde tentaría saltarse la preferencia
     * ("total, es una vez y hace mucha falta"). Si se pudiera, el "no me avises"
     * sería una sugerencia: quien lo comprobara una vez apagaría los avisos del
     * navegador enteros, y ésos ya no se recuperan.
     */
    public function testElOptOutSeRespetaTambienEnElAvisoGeneral(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $dispuesta = $this->makePartner($em, 'VolAudiencia Dispuesta');
        $silenciada = $this->makePartner($em, 'VolAudiencia Silenciada')->setVolunteeringOptOut(true);
        $em->flush();

        $audience = $this->repository($em)->findAllActive();

        $this->assertContains($dispuesta, $audience);
        $this->assertNotContains($silenciada, $audience);
    }

    /**
     * Y tampoco entra por la vía de "no ha declarado preferencias": el silencio
     * de quien no ha marcado nada es ampliable, pero el suyo no es silencio —
     * ha dicho que no expresamente.
     */
    public function testElOptOutQuedaFueraDeQuienNoHaDeclaradoPreferencias(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $callada = $this->makePartner($em, 'VolAudiencia Callada');
        $silenciada = $this->makePartner($em, 'VolAudiencia Silenciada2')->setVolunteeringOptOut(true);
        $em->flush();

        $audience = $this->repository($em)->findActiveWithoutVolunteerPreferences();

        $this->assertContains($callada, $audience);
        $this->assertNotContains($silenciada, $audience);
    }

    /**
     * Ni siquiera si tiene marcada la categoría de la tarea: el "no me avises"
     * gana sobre lo que marcara antes de pedirlo.
     */
    public function testElOptOutGanaSobreLasCategoriasMarcadas(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $categoria = (new VolunteerCategory())->setName('VolAudiencia Categoría');
        $em->persist($categoria);

        $interesada = $this->makePartner($em, 'VolAudiencia Interesada')->addVolunteerCategory($categoria);
        $silenciada = $this->makePartner($em, 'VolAudiencia Silenciada3')
            ->addVolunteerCategory($categoria)
            ->setVolunteeringOptOut(true);
        $em->flush();

        $audience = $this->repository($em)->findActiveMatchingVolunteerCategories([$categoria]);

        $this->assertContains($interesada, $audience);
        $this->assertNotContains($silenciada, $audience);
    }

    /**
     * Sin categorías que cruzar no hay a quién avisar, y la consulta ni siquiera
     * llega a la BBDD. Es el caso que dejaba encalladas las tareas sin tipo de
     * trabajo asignado.
     */
    public function testSinCategoriasNoDevuelveANadie(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $this->assertSame([], $this->repository($em)->findActiveMatchingVolunteerCategories([]));
    }

    private function repository(EntityManagerInterface $em): PartnerRepository
    {
        /** @var PartnerRepository $repository */
        $repository = $em->getRepository(Partner::class);

        return $repository;
    }

    /**
     * Un socix ACTIVO recién creado. El estado por defecto de Partner ya es
     * ACTIVO, que es lo que filtran las tres consultas.
     */
    private function makePartner(EntityManagerInterface $em, string $name): Partner
    {
        $partner = (new Partner())->setName($name);
        $em->persist($partner);

        return $partner;
    }
}
