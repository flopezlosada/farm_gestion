<?php

namespace App\Tests\Repository;

use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Repository\RemittanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Proxy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Las consultas de las remesas contra la base. Periodos en 2097 y un prefijo de
 * mandato propio para no cruzarse con nada.
 */
class RemittanceRepositoryTest extends KernelTestCase
{
    private const PREFIX = 'REPOTEST MENSUAL';

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        foreach ($em->getRepository(Remittance::class)->findAll() as $remittance) {
            if ($remittance->getPeriod()->format('Y') === '2097') {
                $em->remove($remittance);
            }
        }
        $em->flush();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function repo(): RemittanceRepository
    {
        return static::getContainer()->get(RemittanceRepository::class);
    }

    /** @param list<string> $mandates */
    private function imported(string $period, array $mandates, ?Partner $partner = null): Remittance
    {
        $lines = array_map(static fn (string $m): RemittanceLine => (new RemittanceLine())
            ->setPartner($partner)->setDebtorName('SOCIX ' . $m)->setIban('ES9121000418450200051332')
            ->setMandateReference($m)->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'))
            ->setDebitReference($m . ' ' . $period)->setSequenceType('RCUR')->setAmount('50.00'), $mandates);
        $remittance = Remittance::fromImport(Remittance::KIND_BASKET, new \DateTimeImmutable($period . '-01'), 'Recibos ' . $period, 'x.xlsx', $lines, null);
        $this->em()->persist($remittance);
        $this->em()->flush();

        return $remittance;
    }

    /**
     * El mandato nuevo lleva uno más que el mayor usado, aunque haya huecos y aunque
     * el mayor sea de una remesa vieja: el banco prohíbe reutilizar referencias.
     */
    public function testElSiguienteMandatoNoRellenaHuecos(): void
    {
        self::bootKernel();
        $this->imported('2097-01', [self::PREFIX . ' 200', self::PREFIX . ' 214', self::PREFIX . ' 9 BIS']);
        $this->imported('2097-02', [self::PREFIX . ' 200', self::PREFIX . ' 203']);

        $this->assertSame(self::PREFIX . ' 215', $this->repo()->nextMandateReference(self::PREFIX));
        $this->assertSame('REPOTEST_SIN_USAR 1', $this->repo()->nextMandateReference('REPOTEST_SIN_USAR'));
    }

    /**
     * La anterior llega con TODOS sus recibos: con un LIMIT 1 y los recibos en la
     * misma consulta, llegaba con uno solo y la lista de cambios decía que entraba
     * todo el mundo.
     */
    public function testLaAnteriorLlegaConTodosSusRecibos(): void
    {
        self::bootKernel();
        $this->imported('2097-05', ['A 1', 'A 2', 'A 3']);
        $june = $this->imported('2097-06', ['A 1', 'A 2', 'A 3', 'A 4']);
        $this->em()->clear();

        $june = $this->em()->find(Remittance::class, $june->getId());
        $previous = $this->repo()->findLatestOfKind(Remittance::KIND_BASKET, $june->getPeriod());

        $this->assertSame('2097-05', $previous->getPeriod()->format('Y-m'));
        $this->assertCount(3, $previous->getLines());
        $this->assertTrue($this->repo()->existsFor(Remittance::KIND_BASKET, new \DateTimeImmutable('2097-06-20')));
        $this->assertFalse($this->repo()->existsFor(Remittance::KIND_MEMBERSHIP, new \DateTimeImmutable('2097-06-20')));
    }

    /**
     * La ficha y la revisión leen el socix de cada recibo: tienen que llegar cargados
     * en la misma consulta, no uno por recibo. Con una nula (la primera de su tipo no
     * tiene anterior) no falla.
     */
    public function testCargaLosRecibosConSuSocixDeUnaVez(): void
    {
        self::bootKernel();
        $partner = $this->em()->getRepository(Partner::class)->findOneBy([], ['id' => 'ASC']);
        $this->assertNotNull($partner, 'db_test necesita al menos un socix de las fixtures.');
        $remittance = $this->imported('2097-07', ['B 1', 'B 2'], $partner);
        $this->em()->clear();

        $remittance = $this->em()->find(Remittance::class, $remittance->getId());
        $this->repo()->loadLinesWithPartners($remittance, null);

        $this->assertTrue($remittance->getLines()->isInitialized());
        foreach ($remittance->getLines() as $line) {
            $loaded = $line->getPartner();
            $this->assertFalse($loaded instanceof Proxy && !$loaded->__isInitialized(), 'El socix del recibo tiene que venir ya cargado.');
        }
    }
}
