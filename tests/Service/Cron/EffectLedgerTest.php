<?php

namespace App\Tests\Service\Cron;

use App\Entity\EmittedEffect;
use App\Service\Cron\EffectLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El guardián de idempotencia, contra MySQL de verdad.
 *
 * Va con base de datos y no con dobles a propósito: lo que se está probando ES
 * el índice único. Un doble de la conexión probaría que el código llama a
 * insert(), no que dos apuntes con la misma clave sean imposibles, que es la
 * única garantía que aquí importa.
 */
class EffectLedgerTest extends KernelTestCase
{
    private const KIND = 'test_effect';

    /**
     * Los apuntes de estos tests fuera, para no dejar claves que hagan fallar la
     * siguiente pasada de la suite.
     */
    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(EmittedEffect::class)->findBy(['kind' => self::KIND]) as $effect) {
            $em->remove($effect);
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * La primera vez el efecto se produce y queda apuntado con su clave y su
     * destino.
     */
    public function testLaPrimeraVezProduceElEfectoYLoApunta(): void
    {
        self::bootKernel();
        $veces = 0;

        $emitted = $this->ledger()->once(
            self::KIND,
            'partner-1',
            new \DateTimeImmutable('2099-01-15'),
            static function () use (&$veces): void {
                $veces++;
            },
            'socix@test.org',
        );

        $this->assertTrue($emitted);
        $this->assertSame(1, $veces);

        $apunte = $this->findEffect('partner-1', '2099-01-15');
        $this->assertNotNull($apunte, 'El efecto producido debe quedar apuntado.');
        $this->assertSame('socix@test.org', $apunte->getTarget());
    }

    /**
     * El caso que da sentido a todo esto: repetir la tarea no repite el efecto.
     * Es el criterio de aceptación del paso 2 — lanzar el recordatorio dos veces
     * seguidas no manda ni un correo repetido.
     */
    public function testLaSegundaVezNoLoProduce(): void
    {
        self::bootKernel();
        $veces = 0;
        $efecto = static function () use (&$veces): void {
            $veces++;
        };
        $ledger = $this->ledger();

        $primera = $ledger->once(self::KIND, 'partner-2', new \DateTimeImmutable('2099-01-15'), $efecto);
        $segunda = $ledger->once(self::KIND, 'partner-2', new \DateTimeImmutable('2099-01-15'), $efecto);

        $this->assertTrue($primera);
        $this->assertFalse($segunda, 'La segunda llamada con la misma clave no debe producir el efecto.');
        $this->assertSame(1, $veces, 'El efecto se ha producido una sola vez.');
    }

    /**
     * La fecha forma parte de la clave: el aviso del reparto de esta semana no
     * bloquea el de la semana siguiente. Sin esto, el recordatorio sólo se
     * mandaría una vez en la vida a cada socix.
     */
    public function testOtraFechaEsOtroEfecto(): void
    {
        self::bootKernel();
        $veces = 0;
        $efecto = static function () use (&$veces): void {
            $veces++;
        };
        $ledger = $this->ledger();

        $ledger->once(self::KIND, 'partner-3', new \DateTimeImmutable('2099-01-15'), $efecto);
        $ledger->once(self::KIND, 'partner-3', new \DateTimeImmutable('2099-01-22'), $efecto);

        $this->assertSame(2, $veces, 'Dos repartos distintos son dos efectos distintos.');
    }

    /**
     * Y la referencia también: dos socixs del mismo día son dos avisos.
     */
    public function testOtraReferenciaEsOtroEfecto(): void
    {
        self::bootKernel();
        $veces = 0;
        $efecto = static function () use (&$veces): void {
            $veces++;
        };
        $ledger = $this->ledger();

        $ledger->once(self::KIND, 'partner-4', new \DateTimeImmutable('2099-01-15'), $efecto);
        $ledger->once(self::KIND, 'partner-5', new \DateTimeImmutable('2099-01-15'), $efecto);

        $this->assertSame(2, $veces);
    }

    /**
     * Si el efecto falla, el apunte se retira y el siguiente intento lo recoge.
     * Es lo que hace que un SMTP caído a mitad del envío no deje a nadie sin
     * aviso para siempre.
     */
    public function testUnEfectoQueFallaNoQuedaApuntadoYSeReintenta(): void
    {
        self::bootKernel();
        $ledger = $this->ledger();
        $fecha = new \DateTimeImmutable('2099-01-15');

        $thrown = null;
        try {
            $ledger->once(self::KIND, 'partner-6', $fecha, static function (): void {
                throw new \RuntimeException('SMTP caído de prueba');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'El fallo debe seguir su curso: el guardián no se lo traga.');
        $this->assertNull($this->findEffect('partner-6', '2099-01-15'), 'Un efecto que no llegó a producirse no debe quedar apuntado.');

        $reintento = $ledger->once(self::KIND, 'partner-6', $fecha, static function (): void {
        });
        $this->assertTrue($reintento, 'El reintento debe poder producir el efecto que falló.');
    }

    /**
     * Un destino más largo que la columna no puede reventar el INSERT y con él
     * el envío: se recorta al persistir.
     */
    public function testUnDestinoLarguisimoNoRevientaElApunte(): void
    {
        self::bootKernel();

        $emitted = $this->ledger()->once(
            self::KIND,
            'partner-7',
            new \DateTimeImmutable('2099-01-15'),
            static function (): void {
            },
            str_repeat('a', 400) . '@test.org',
        );

        $this->assertTrue($emitted);
        $this->assertSame(255, mb_strlen((string) $this->findEffect('partner-7', '2099-01-15')?->getTarget()));
    }

    /**
     * Servicio real del contenedor de test.
     */
    private function ledger(): EffectLedger
    {
        return self::getContainer()->get(EffectLedger::class);
    }

    /**
     * Apunte guardado para una clave, o null si no hay.
     *
     * @param string $reference  Referencia del efecto.
     * @param string $occurredOn Fecha de negocio en formato YYYY-MM-DD.
     */
    private function findEffect(string $reference, string $occurredOn): ?EmittedEffect
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        // El guardián escribe por DBAL, así que el EntityManager puede tener en
        // su identity map una versión anterior (o la ausencia) de estas filas.
        $em->clear();

        return $em->getRepository(EmittedEffect::class)->findOneBy([
            'kind' => self::KIND,
            'reference' => $reference,
            'occurredOn' => new \DateTimeImmutable($occurredOn),
        ]);
    }
}
