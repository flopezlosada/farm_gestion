<?php

namespace App\Tests\Command;

use App\Entity\Worker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * El correo personal del trabajador tampoco puede llegar a staging. El comando
 * reescribe TODOS los correos de la base, así que corre dentro de una transacción
 * que se deshace al acabar: las fixtures conservan los suyos.
 */
class AnonymizeStagingCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        $this->em->clear();

        parent::tearDown();
    }

    /**
     * @param string|null $email
     * @return Worker
     */
    private function makeWorker(?string $email): Worker
    {
        $worker = (new Worker())
            ->setName('Alba')
            ->setHireDate(new \DateTimeImmutable('2026-01-01'))
            ->setEmail($email);
        $this->em->persist($worker);
        $this->em->flush();

        return $worker;
    }

    /** Se faketea el de quien no es tester, se respeta el del tester y no se inventa uno a quien no lo tiene. */
    public function testFaketeaElCorreoDelTrabajador(): void
    {
        $real = $this->makeWorker('alba@example.org');
        $tester = $this->makeWorker('Tester@Example.org');
        $without = $this->makeWorker(null);

        $command = (new Application(self::$kernel))->find('app:anonymize-staging');
        (new CommandTester($command))->execute(['keep' => ['tester@example.org']]);

        $this->em->clear();
        $repository = $this->em->getRepository(Worker::class);
        $this->assertSame(sprintf('trabajador-%d@staging.invalid', $real->getId()), $repository->find($real->getId())->getEmail());
        $this->assertSame('Tester@Example.org', $repository->find($tester->getId())->getEmail());
        $this->assertNull($repository->find($without->getId())->getEmail());
    }
}
