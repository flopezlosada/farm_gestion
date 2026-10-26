<?php

namespace App\Tests\Repository;

use App\Entity\Budget;
use App\Repository\BudgetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Contra qué presupuesto se mide un año ({@see BudgetRepository::findReferenceForYear})
 * y cómo se pone uno en vigor ({@see BudgetRepository::putInForce}): el en vigor manda,
 * sin él el aprobado, y nunca hay dos en vigor el mismo año.
 *
 * Autocontenido: año 2097, sin nada más.
 */
class BudgetRepositoryReferenceTest extends KernelTestCase
{
    private const YEAR = 2097;

    private EntityManagerInterface $em;
    private BudgetRepository $budgets;
    /** @var list<Budget> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->budgets = $this->em->getRepository(Budget::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->created as $budget) {
            $found = $this->em->find(Budget::class, $budget->getId());
            if ($found !== null) {
                $this->em->remove($found);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    /**
     * Sin ninguno en vigor, el aprobado; una revisión sin aprobar no cuenta.
     */
    public function testSinNingunoEnVigorSeMideContraElAprobado(): void
    {
        $approved = $this->budget('Aprobado en asamblea', true);
        $this->budget('Revisión de octubre', false);

        $this->assertSame($approved->getId(), $this->budgets->findReferenceForYear(self::YEAR)?->getId());
        $this->assertNull($this->budgets->findReferenceForYear(self::YEAR + 1), 'un año sin presupuestos no tiene referencia');
    }

    /**
     * La revisión en vigor manda sobre el aprobado, y la asamblea sigue viendo el
     * aprobado.
     */
    public function testElEnVigorMandaSobreElAprobado(): void
    {
        $approved = $this->budget('Aprobado en asamblea', true);
        $revised = $this->budget('Revisión de octubre', false);

        $this->budgets->putInForce($revised);

        $this->assertSame($revised->getId(), $this->budgets->findReferenceForYear(self::YEAR)?->getId());
        $this->assertSame($approved->getId(), $this->budgets->findApprovedForYear(self::YEAR)?->getId());
    }

    /**
     * Poner otro en vigor saca al anterior, también si éste está cargado en memoria;
     * repetirlo sobre el que ya lo está no lo deja sin vigor.
     */
    public function testPonerOtroEnVigorSacaAlAnterior(): void
    {
        $this->budget('Aprobado en asamblea', true);
        $first = $this->budget('Revisión de julio', false);
        $second = $this->budget('Revisión de octubre', false);

        $this->budgets->putInForce($first);
        $this->budgets->putInForce($second);
        $this->budgets->putInForce($second);
        $this->em->clear();

        $this->assertFalse($this->em->find(Budget::class, $first->getId())->isInForce());
        $this->assertTrue($this->em->find(Budget::class, $second->getId())->isInForce());
        $this->assertSame($second->getId(), $this->budgets->findReferenceForYear(self::YEAR)?->getId());
    }

    /**
     * Sacarlo de vigor devuelve el año al aprobado.
     */
    public function testSacarDeVigorVuelveAlAprobado(): void
    {
        $approved = $this->budget('Aprobado en asamblea', true);
        $revised = $this->budget('Revisión de octubre', false);
        $this->budgets->putInForce($revised);

        $revised->clearInForce();
        $this->em->flush();

        $this->assertSame($approved->getId(), $this->budgets->findReferenceForYear(self::YEAR)?->getId());
    }

    private function budget(string $name, bool $approved): Budget
    {
        $budget = (new Budget())->setYear(self::YEAR)->setName($name.' '.bin2hex(random_bytes(3)))
            ->setApproved($approved)
            ->setApprovedAt($approved ? new \DateTimeImmutable('2097-01-20') : null);
        $this->em->persist($budget);
        $this->em->flush();
        $this->created[] = $budget;

        return $budget;
    }
}
