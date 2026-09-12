<?php

namespace App\Tests\Command;

use App\Entity\EmittedEffect;
use App\Entity\Obligation;
use App\Entity\ObligationTerm;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La tarea que avisa de lo que caduca.
 *
 * Cubre los dos modos de fallo que importan: que no mande nada con el módulo
 * apagado (avisar de algo que no se puede ni abrir), y que NO REPITA el mismo
 * aviso en cada pasada — con una tarea diaria, un aviso que se repite deja de
 * leerse en tres días y entonces el sistema entero no sirve.
 */
class SendObligationNoticesCommandTest extends KernelTestCase
{
    /** @var list<int> Ids de obligaciones creadas por el test, para limpiarlas. */
    private array $created = [];

    protected function tearDown(): void
    {
        if (self::$kernel === null) {
            parent::tearDown();

            return;
        }

        $em = self::getContainer()->get(EntityManagerInterface::class);

        foreach ($this->created as $id) {
            $obligation = $em->getRepository(Obligation::class)->find($id);
            if ($obligation !== null) {
                // Los apuntes del guardián de idempotencia se van con la
                // obligación: si se quedaran, el siguiente test creería que ya
                // se avisó de algo que acaba de nacer.
                foreach ($em->getRepository(EmittedEffect::class)->findBy(['kind' => 'obligation_notice']) as $effect) {
                    if (str_starts_with((string) $effect->getReference(), sprintf('obligation-%d:', $id))) {
                        $em->remove($effect);
                    }
                }
                $em->remove($obligation);
            }
        }

        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * Con el módulo apagado la tarea ni se ejecuta: está en su `requires`. Es
     * deliberado — un correo avisando de algo que no se puede ni abrir no es un
     * aviso, es un susto.
     */
    public function testConElModuloApagadoLaTareaNoCorre(): void
    {
        self::bootKernel();
        $this->createObligation('TEST convenio apagado', '+10 days');

        $tester = $this->commandTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringNotContainsString('TEST convenio apagado', $tester->getDisplay());
    }

    /**
     * El ensayo en seco lista lo que avisaría, sin mandar nada y sin apuntar
     * nada: tiene que poder repetirse sin gastar el aviso de verdad.
     */
    public function testElEnsayoEnSecoListaSinGastarElAviso(): void
    {
        self::bootKernel();
        $this->enableModule();
        $this->createObligation('TEST poliza en seco', '+45 days');

        $tester = $this->commandTester();
        $tester->execute(['--dry-run' => true]);

        $display = $this->plainDisplay($tester);
        $this->assertStringContainsString('TEST poliza en seco', $display);
        // 45 días cruza el escalón de 60, no el de 90: se avisa del más urgente.
        $this->assertStringContainsString('escalón 60', $display);

        // Y no ha quedado apuntado: la ejecución de verdad todavía debe avisar.
        $tester->execute([]);
        $this->assertStringContainsString('1 aviso(s) enviados', $this->plainDisplay($tester));
    }

    /**
     * Lo que aún no ha entrado en el radar no se toca.
     */
    public function testLoQueVenceMuyLejosNoGeneraAviso(): void
    {
        self::bootKernel();
        $this->enableModule();
        $this->createObligation('TEST convenio lejano', '+200 days');

        $tester = $this->commandTester();
        $tester->execute([]);

        $this->assertStringContainsString('Ninguna obligación cruza', $this->plainDisplay($tester));
    }

    /**
     * El mismo aviso no sale dos veces. Con la tarea corriendo a diario, esto
     * es lo que separa un recordatorio útil de un correo que se aprende a
     * ignorar.
     */
    public function testElMismoAvisoNoSaleDosVeces(): void
    {
        self::bootKernel();
        $this->enableModule();
        $this->createObligation('TEST rega repetido', '+30 days');

        $tester = $this->commandTester();

        $tester->execute([]);
        $this->assertStringContainsString('1 aviso(s) enviados', $this->plainDisplay($tester));

        $tester->execute([]);
        $display = $this->plainDisplay($tester);
        $this->assertStringNotContainsString('1 aviso(s) enviados', $display);
        $this->assertStringContainsString('Todas avisadas ya', $display);
    }

    /**
     * La salida con los espacios normalizados.
     *
     * SymfonyStyle parte los avisos largos en varias líneas y las rellena con
     * espacios para pintar el bloque, así que buscar una frase en la salida
     * cruda falla aunque el texto esté.
     *
     * @param CommandTester $tester Ejecución ya corrida.
     */
    private function plainDisplay(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * Enciende el módulo y el correo para la ejecución.
     */
    private function enableModule(): void
    {
        $settings = self::getContainer()->get(AppSettings::class);
        $settings->setBool(AppSettings::FEATURE_VENCIMIENTOS, true);
        $settings->setBool(AppSettings::EMAIL_ENABLED, true);
        $settings->setBool(AppSettings::EMAIL_OBLIGATION_NOTICE, true);
    }

    /**
     * Crea una obligación con un único periodo que acaba dentro del plazo dado.
     *
     * @param string $name     Nombre, prefijado con TEST para reconocerlo.
     * @param string $endsIn   Desplazamiento relativo ("+30 days").
     */
    private function createObligation(string $name, string $endsIn): Obligation
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $obligation = new Obligation();
        $obligation->setName($name);
        $obligation->setKind(Obligation::KIND_AGREEMENT);

        $term = new ObligationTerm();
        $term->setEndsOn(new \DateTimeImmutable('today ' . $endsIn));
        $obligation->addTerm($term);

        $em->persist($obligation);
        $em->persist($term);
        $em->flush();

        $this->created[] = $obligation->getId();

        return $obligation;
    }

    /**
     * CommandTester del comando (el kernel ya debe estar arrancado).
     */
    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:send-obligation-notices'));
    }
}
