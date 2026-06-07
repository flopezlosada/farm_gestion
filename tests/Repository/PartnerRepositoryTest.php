<?php

namespace App\Tests\Repository;

use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test de la elegibilidad de candidatos a familiar (findFamiliar).
 * Autocontenido: crea sus propios socios, no depende del estado de db_test.
 *
 * Un familiar pasa a ser secundario (parent = principal) y su cesta vive en el
 * principal, así que findFamiliar sólo debe ofrecer socixs activos, sin familia
 * previa (parent nulo) y sin cesta propia activa — esto último ataja en origen
 * el borrado destructivo de PartnerBasketShare que hacía addFamily.
 */
class PartnerRepositoryTest extends KernelTestCase
{
    private const SHARE_WEEKLY = 1;

    public function testFindFamiliarSoloOfreceCandidatosElegibles(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $weekly = $em->getRepository(BasketShare::class)->find(self::SHARE_WEEKLY);
        $this->assertNotNull($weekly, 'El catálogo debe tener la cesta semanal (id 1).');

        // Principal sobre el que pedimos candidatos: activo, con cesta y un familiar ya suyo.
        $principal = $this->makePartner($em, 'RepoFam Principal', true);
        $this->makeActiveShare($em, $weekly, $principal);

        $existingRelative = $this->makePartner($em, 'RepoFam YaFamiliar', true);
        $principal->addRelative($existingRelative); // pone parent = principal

        // Candidato VÁLIDO: activo, sin familia, sin cesta propia.
        $eligible = $this->makePartner($em, 'RepoFam Elegible', true);

        // Candidato INVÁLIDO: tiene cesta propia activa (asociarlo la destruiría).
        $withOwnBasket = $this->makePartner($em, 'RepoFam ConCesta', true);
        $this->makeActiveShare($em, $weekly, $withOwnBasket);

        // Candidato INVÁLIDO: ya pertenece a OTRA familia (asociarlo lo robaría).
        $otherPrincipal = $this->makePartner($em, 'RepoFam OtroPrincipal', true);
        $inOtherFamily = $this->makePartner($em, 'RepoFam EnOtraFamilia', true);
        $otherPrincipal->addRelative($inOtherFamily);

        // Candidato INVÁLIDO: inactivo.
        $inactive = $this->makePartner($em, 'RepoFam Inactivo', false);

        $em->flush();

        /** @var PartnerRepository $repo */
        $repo = $em->getRepository(Partner::class);
        $ids = array_map(static fn (Partner $p): int => $p->getId(), $repo->findFamiliar($principal));

        $this->assertContains($eligible->getId(), $ids, 'Un socix activo, sin familia y sin cesta propia debe ser candidato.');
        $this->assertNotContains($withOwnBasket->getId(), $ids, 'Un socix con cesta propia activa NO debe ofrecerse (asociarlo la destruiría).');
        $this->assertNotContains($inOtherFamily->getId(), $ids, 'Un socix que ya pertenece a otra familia NO debe ofrecerse.');
        $this->assertNotContains($inactive->getId(), $ids, 'Un socix inactivo NO debe ofrecerse.');
        $this->assertNotContains($existingRelative->getId(), $ids, 'Un familiar ya asociado NO debe reaparecer.');
        $this->assertNotContains($principal->getId(), $ids, 'El propio principal NO debe ofrecerse a sí mismo.');
    }

    private function makePartner(EntityManagerInterface $em, string $name, bool $active): Partner
    {
        $partner = new Partner();
        $partner->setName($name);
        $partner->setIsActive($active);
        $em->persist($partner);

        return $partner;
    }

    private function makeActiveShare(EntityManagerInterface $em, BasketShare $weekly, Partner $partner): PartnerBasketShare
    {
        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($weekly);
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setMonthPrice('0.00');
        $share->setEggMonthPrice('0.00');
        $share->setStartDate(new \DateTime('2099-01-01'));
        $share->setEndDate(null);
        $em->persist($share);

        return $share;
    }
}
