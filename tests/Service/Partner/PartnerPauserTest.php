<?php

namespace App\Tests\Service\Partner;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerEvent;
use App\Entity\PartnerPause;
use App\Entity\WeeklyBasket;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Partner\PartnerPauser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pausas con fecha contra db_test: lo que de verdad importa es que quien está en
 * pausa no salga en el reparto (ni dibujado ni congelado) y que la ficha cambie el
 * día que toca, con el evento fechado ese día.
 *
 * Cada test crea su socix semanal sin nodo (su entrega cae el día del Basket) y sus
 * Baskets en fechas lejanas propias, porque db_test no deshace entre tests. "Hoy"
 * se inyecta para no depender de la fecha real.
 */
class PartnerPauserTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function pauser(): PartnerPauser
    {
        return static::getContainer()->get(PartnerPauser::class);
    }

    private function generator(): WeeklyBasketGenerator
    {
        return static::getContainer()->get(WeeklyBasketGenerator::class);
    }

    private static function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    /** Socix semanal activx desde 2090, sin grupo (sin nodo). */
    private function weeklyPartner(string $name): Partner
    {
        $em = $this->em();
        $partner = (new Partner())->setname($name);
        $em->persist($partner);

        $share = new PartnerBasketShare();
        $share->setPartner($partner);
        $share->setBasketShare($em->getRepository(BasketShare::class)->find(BasketShare::ID_WEEKLY));
        $share->setIsActive(true);
        $share->setAmount(1);
        $share->setStartDate(new \DateTime('2090-01-01'));
        $em->persist($share);
        $em->flush();

        return $partner;
    }

    private function basket(string $date): Basket
    {
        $basket = new Basket();
        $basket->setDate(new \DateTime($date));
        $basket->setWeek((int) (new \DateTime($date))->format('W'));
        $basket->setAmount(1);
        $this->em()->persist($basket);
        $this->em()->flush();

        return $basket;
    }

    private function isCandidate(Partner $partner, Basket $basket): bool
    {
        foreach ($this->generator()->projectForBasket($basket) as $share) {
            if ($share->getPartner()->getId() === $partner->getId()) {
                return true;
            }
        }

        return false;
    }

    private function stone(Partner $partner, Basket $basket): ?WeeklyBasket
    {
        return $this->em()->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket->getId(), 'partner' => $partner->getId()]);
    }

    /** @return list<PartnerEvent> */
    private function events(Partner $partner, string $type): array
    {
        return $this->em()->getRepository(PartnerEvent::class)->findBy(['partner' => $partner, 'type' => $type]);
    }

    /**
     * Una pausa a futuro no toca la ficha ni deja evento hasta que llega: la ficha sigue
     * activa, pero el reparto de las semanas de la pausa ya no le cuenta. El día de
     * vuelta vuelve a ser candidatx.
     */
    public function testUnaPausaProgramadaSaleDelRepartoPeroNoTocaLaFichaHastaSuDia(): void
    {
        self::bootKernel();
        $partner = $this->weeklyPartner('Pausa Programada');
        $before = $this->basket('2099-06-05');
        $during = $this->basket('2099-06-19');
        $back = $this->basket('2099-06-26');

        $this->pauser()->pause($partner, self::day('2099-06-12'), self::day('2099-06-26'), 'gestor:1', self::day('2099-06-01'));

        $this->assertSame(Partner::STATUS_ACTIVO, $partner->getStatus(), 'Hasta el 12 sigue activx.');
        $this->assertSame([], $this->events($partner, PartnerEvent::TYPE_PAUSE), 'Ningún evento antes de que empiece.');
        $this->assertTrue($this->isCandidate($partner, $before));
        $this->assertFalse($this->isCandidate($partner, $during), 'En pausa no es candidatx.');
        $this->assertTrue($this->isCandidate($partner, $back), 'El día de vuelta ya tiene cesta.');

        // La tarea diaria, el día que empieza y el día que vuelve.
        $this->assertTrue($this->pauser()->syncStatus($partner, self::day('2099-06-12')));
        $this->em()->flush();
        $this->assertSame(Partner::STATUS_PAUSADO, $partner->getStatus());
        $this->assertSame('2099-06-12', $this->events($partner, PartnerEvent::TYPE_PAUSE)[0]->getOccurredAt()->format('Y-m-d'));
        $this->assertFalse($this->pauser()->syncStatus($partner, self::day('2099-06-13')), 'Un día después no hay nada que cambiar.');

        $this->assertTrue($this->pauser()->syncStatus($partner, self::day('2099-06-26')));
        $this->em()->flush();
        $this->assertSame(Partner::STATUS_ACTIVO, $partner->getStatus());
        $this->assertSame('2099-06-26', $this->events($partner, PartnerEvent::TYPE_RESUME)[0]->getOccurredAt()->format('Y-m-d'));
    }

    /**
     * El caso que lo originó: la semana ya está congelada cuando se pausa. Pausar le
     * quita la entrega de esa semana y reactivar se la devuelve; las dos cosas dejan
     * evento con su fecha.
     */
    public function testPausarQuitaLaEntregaCongeladaYReactivarLaDevuelve(): void
    {
        self::bootKernel();
        $partner = $this->weeklyPartner('Pausa Congelada');
        $basket = $this->basket('2098-03-06');
        $this->generator()->generateForBasket($basket);
        $this->assertNotNull($this->stone($partner, $basket), 'Antes de pausar, la semana congelada le incluye.');

        $today = self::day('2098-03-02');
        $this->pauser()->pause($partner, self::day('2098-03-01'), null, 'gestor:1', $today);

        $this->assertNull($this->stone($partner, $basket), 'Pausadx: su entrega congelada desaparece.');
        $this->assertSame(Partner::STATUS_PAUSADO, $partner->getStatus());
        $this->assertSame('2098-03-01', $this->events($partner, PartnerEvent::TYPE_PAUSE)[0]->getOccurredAt()->format('Y-m-d'), 'El evento lleva el día en que empieza, no el de hoy.');

        $cancelled = $this->pauser()->resume($partner, self::day('2098-03-04'), $today);

        $this->assertFalse($cancelled, 'Ya había empezado: se recorta, no se anula.');
        $this->assertNotNull($this->stone($partner, $basket), 'Reactivadx antes del reparto: la entrega vuelve.');
        $this->assertSame(Partner::STATUS_PAUSADO, $partner->getStatus(), 'Hoy es día 2 y vuelve el 4: la ficha sigue en pausa hasta entonces.');
        $this->assertSame('2098-03-04', $this->em()->getRepository(PartnerPause::class)->findOneBy(['partner' => $partner])->getResumesOn()->format('Y-m-d'));
    }

    /** Una pausa que aún no ha empezado se anula entera al reactivar antes de su inicio. */
    public function testReactivarAntesDeQueEmpieceAnulaLaPausa(): void
    {
        self::bootKernel();
        $partner = $this->weeklyPartner('Pausa Anulada');
        $today = self::day('2097-05-01');
        $this->pauser()->pause($partner, self::day('2097-05-10'), null, 'gestor:1', $today);

        $this->assertTrue($this->pauser()->resume($partner, self::day('2097-05-05'), $today));

        $this->assertSame([], $this->em()->getRepository(PartnerPause::class)->findBy(['partner' => $partner]));
        $this->assertSame(Partner::STATUS_ACTIVO, $partner->getStatus());
        $this->assertSame([], $this->events($partner, PartnerEvent::TYPE_PAUSE), 'Nunca llegó a estar en pausa.');
    }

    public function testNoSePuedenCruzarDosPausas(): void
    {
        self::bootKernel();
        $partner = $this->weeklyPartner('Pausa Solapada');
        $today = self::day('2096-01-01');
        $this->pauser()->pause($partner, self::day('2096-02-01'), self::day('2096-03-01'), 'gestor:1', $today);

        // Seguida sí: empieza el día que vuelve.
        $this->pauser()->pause($partner, self::day('2096-03-01'), null, 'gestor:1', $today);

        $this->expectException(\DomainException::class);
        $this->pauser()->pause($partner, self::day('2096-02-15'), self::day('2096-02-20'), 'gestor:1', $today);
    }

    public function testNoSePausaAQuienEstaDeBaja(): void
    {
        self::bootKernel();
        $partner = $this->weeklyPartner('Pausa Baja');
        $partner->setStatus(Partner::STATUS_BAJA);

        $this->expectException(\DomainException::class);
        $this->pauser()->pause($partner, self::day('2095-01-10'), null, 'gestor:1', self::day('2095-01-01'));
    }

    public function testLaVueltaTieneQueSerFutura(): void
    {
        self::bootKernel();
        $partner = $this->weeklyPartner('Pausa Pasada');

        $this->expectException(\DomainException::class);
        $this->pauser()->pause($partner, self::day('2094-01-01'), self::day('2094-01-10'), 'gestor:1', self::day('2094-01-10'));
    }

    /**
     * Alargar una pausa (reactivar más tarde de lo previsto) quita también las entregas
     * congeladas del tramo añadido: si sólo se rehiciera desde la vuelta nueva, esas
     * semanas seguirían en el listado con la ficha en pausa.
     */
    public function testAlargarUnaPausaQuitaLasEntregasDelTramoAnadido(): void
    {
        self::bootKernel();
        $partner = $this->weeklyPartner('Pausa Alargada');
        $added = $this->basket('2093-04-17');
        $this->generator()->generateForBasket($added);
        $today = self::day('2093-04-01');
        $this->pauser()->pause($partner, self::day('2093-04-05'), self::day('2093-04-12'), 'gestor:1', $today);
        $this->assertNotNull($this->stone($partner, $added), 'El 17 queda fuera de la pausa del 5 al 12.');

        $this->pauser()->resume($partner, self::day('2093-04-20'), $today);

        $this->assertNull($this->stone($partner, $added), 'Ahora vuelve el 20: la entrega del 17 sobra.');
    }

    /**
     * Dar de baja a quien está en pausa cierra la pausa: al volver al alta recibe cesta
     * (y paga) sin que la tarea diaria le vuelva a pausar.
     */
    public function testLaBajaCierraLaPausa(): void
    {
        self::bootKernel();
        $partner = $this->weeklyPartner('Pausa y Baja');
        $this->pauser()->pause($partner, new \DateTimeImmutable('yesterday'), null, 'gestor:1');
        $this->assertSame(Partner::STATUS_PAUSADO, $partner->getStatus());

        $workflow = static::getContainer()->get('state_machine.partner_lifecycle');
        $workflow->apply($partner, 'leave');
        $this->em()->flush();
        $workflow->apply($partner, 'rejoin');
        $this->em()->flush();

        $pause = $this->em()->getRepository(PartnerPause::class)->findOneBy(['partner' => $partner]);
        $this->assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $pause?->getResumesOn()?->format('Y-m-d'), 'Termina el día de la baja.');
        $this->assertFalse($this->pauser()->syncStatus($partner, new \DateTimeImmutable('today')), 'Ya de vuelta, nada que volver a pausar.');
        $this->assertSame(Partner::STATUS_ACTIVO, $partner->getStatus());
    }
}
