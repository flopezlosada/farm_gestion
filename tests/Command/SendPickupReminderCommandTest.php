<?php

namespace App\Tests\Command;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\DeliveryException;
use App\Entity\EmittedEffect;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\Setting;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketStatus;
use App\Service\AppSettings;
use App\Service\Delivery\PickupReminderMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests del comando del recordatorio de recogida CONSCIENTE DEL NODO. Cubre lo
 * crítico: que NO envía con el toggle apagado (el modo de fallo peligroso) y que
 * el dry-run selecciona a cada nodo por su FECHA FÍSICA de reparto —Madrid el
 * miércoles, la Sierra el viernes—, que es justo el fallo que confundió a Madrid
 * en producción. No muta configuración: el toggle de email está apagado por
 * defecto y el dry-run lo salta.
 *
 * Autocontenido: nodos/fechas propios (2099) para no colisionar con las fixtures.
 */
class SendPickupReminderCommandTest extends KernelTestCase
{
    private const MADRID_WED = '2099-07-01';
    private const SIERRA_FRI = '2099-07-03';

    /**
     * Con el toggle de email apagado (su default), el comando sale en verde sin
     * enviar nada y avisándolo. El gate del cron (default ON) lo deja pasar y se
     * para en el del email.
     */
    public function testNoEnviaConElToggleApagado(): void
    {
        self::bootKernel();
        $tester = $this->commandTester();

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('desactivado', $tester->getDisplay());
    }

    /**
     * El dry-run selecciona a cada nodo por su fecha física: pedir el miércoles
     * lista a Madrid (Cascorro) y NO a la Sierra; pedir el viernes, al revés.
     */
    public function testDryRunSeleccionaCadaNodoPorSuFechaFisica(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $sufijo = uniqid();
        $picks = $em->getRepository(WeeklyBasketStatus::class)->find(1);
        $biweekly = $em->getRepository(BasketShare::class)->find(BasketShare::ID_BIWEEKLY);

        $basket = (new Basket())->setDate(new \DateTime(self::SIERRA_FRI))->setWeek(27)->setAmount(1);
        $em->persist($basket);

        $cascorro = (new Node())->setName('Cascorro TEST ' . $sufijo)->setDeliveryWeekday(3);
        $torremocha = (new Node())->setName('Torremocha TEST ' . $sufijo)->setDeliveryWeekday(5);
        $grupoMadrid = (new WeeklyBasketGroup())->setName('Cascorro')->setColor('#8fbf5a')->setNode($cascorro);
        $grupoSierra = (new WeeklyBasketGroup())->setName('Bustarviejo')->setColor('#5a8fbf')->setNode($torremocha);
        $em->persist($cascorro);
        $em->persist($torremocha);
        $em->persist($grupoMadrid);
        $em->persist($grupoSierra);

        $nombreMadrid = 'MadridSocix ' . $sufijo;
        $nombreSierra = 'SierraSocix ' . $sufijo;
        $wbMadrid = $this->makeWeeklyBasket($em, $basket, $biweekly, $picks, $grupoMadrid, self::MADRID_WED, $nombreMadrid);
        $wbSierra = $this->makeWeeklyBasket($em, $basket, $biweekly, $picks, $grupoSierra, self::SIERRA_FRI, $nombreSierra);
        $em->flush();

        $tester = $this->commandTester();

        $tester->execute(['--dry-run' => true, '--date' => self::MADRID_WED]);
        $miercoles = $tester->getDisplay();
        $this->assertStringContainsString($nombreMadrid, $miercoles, 'El miércoles debe listar a Madrid.');
        $this->assertStringContainsString('Cascorro TEST ' . $sufijo, $miercoles, 'Debe mostrar el nodo de Madrid.');
        $this->assertStringNotContainsString($nombreSierra, $miercoles, 'La Sierra (viernes) NO debe salir el miércoles.');

        $tester->execute(['--dry-run' => true, '--date' => self::SIERRA_FRI]);
        $viernes = $tester->getDisplay();
        $this->assertStringContainsString($nombreSierra, $viernes, 'El viernes debe listar a la Sierra.');
        $this->assertStringNotContainsString($nombreMadrid, $viernes, 'Madrid (miércoles) NO debe salir el viernes.');

        // Limpieza.
        foreach ([$wbMadrid, $wbSierra] as $wb) {
            $partner = $wb->getPartner();
            $em->remove($wb);
            $em->flush();
            $em->remove($partner);
        }
        $em->remove($grupoMadrid);
        $em->remove($grupoSierra);
        $em->remove($cascorro);
        $em->remove($torremocha);
        $em->remove($basket);
        $em->flush();
    }

    /**
     * QUIEN COMPARTE CESTA TAMBIÉN RECIBE EL AVISO. Las modalidades compartidas
     * —quincenal compartida y mensual compartida— reparten con la misma cadencia
     * que sus hermanas y van a recoger el mismo día, pero la consulta pedía sólo
     * las no compartidas y esas familias no recibieron nunca el recordatorio.
     *
     * Las semanales siguen fuera, y eso sí es deliberado: recogen todas las
     * semanas y no hay nada que recordarles. El caso va en el mismo proveedor
     * para que ampliar la selección de más no pase inadvertido.
     *
     * @dataProvider modalidadesYSiEsperanAviso
     */
    public function testAvisaATodaLaCadenciaQuincenalYMensualIncluidasLasCompartidas(int $shareId, bool $esperaAviso): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $sufijo = uniqid();
        $fecha = '2099-10-02';
        $picks = $em->getRepository(WeeklyBasketStatus::class)->find(1);
        $share = $em->getRepository(BasketShare::class)->find($shareId);

        $basket = (new Basket())->setDate(new \DateTime($fecha))->setWeek(40)->setAmount(1);
        $em->persist($basket);
        $node = (new Node())->setName('Torremocha TEST ' . $sufijo)->setDeliveryWeekday(5);
        $grupo = (new WeeklyBasketGroup())->setName('Bustarviejo')->setColor('#5a8fbf')->setNode($node);
        $em->persist($node);
        $em->persist($grupo);

        $nombre = 'CompartidaSocix ' . $sufijo;
        $wb = $this->makeWeeklyBasket($em, $basket, $share, $picks, $grupo, $fecha, $nombre);
        $em->flush();

        try {
            $tester = $this->commandTester();
            $tester->execute(['--dry-run' => true, '--date' => $fecha]);
            $display = $tester->getDisplay();

            $esperaAviso
                ? $this->assertStringContainsString($nombre, $display, 'Esta modalidad recoge ese día y debe recibir el aviso.')
                : $this->assertStringNotContainsString($nombre, $display, 'Las semanales recogen cada semana: no se les avisa.');
        } finally {
            $partner = $wb->getPartner();
            $em->remove($wb);
            $em->flush();
            $em->remove($partner);
            $em->remove($grupo);
            $em->remove($node);
            $em->remove($basket);
            $em->flush();
        }
    }

    /** @return iterable<string, array{int, bool}> */
    public static function modalidadesYSiEsperanAviso(): iterable
    {
        yield 'quincenal compartida' => [BasketShare::ID_BIWEEKLY_SHARED, true];
        yield 'mensual compartida' => [BasketShare::ID_MONTHLY_SHARED, true];
        yield 'mensual' => [BasketShare::ID_MONTHLY, true];
        yield 'semanal, fuera a propósito' => [BasketShare::ID_WEEKLY, false];
    }

    /**
     * LA TAREA SE DELATA A SÍ MISMA: al terminar comprueba su propia cobertura,
     * y si alguien que recogía y podía recibir el aviso se ha quedado sin él,
     * lo dice en pantalla y en el registro de ejecuciones.
     *
     * El escenario es el más realista de todos: los envíos por correo están
     * apagados y esta persona no tiene cuenta en la web, así que no le llega
     * por ningún canal. Antes, eso salía en verde y en silencio — que es
     * exactamente cómo pasaron desapercibidos dos meses sin avisar a las cestas
     * compartidas.
     */
    public function testAlTerminarAvisaDeQuienSeQuedoSinAviso(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        // Fecha exclusiva de este test: la cobertura cuenta TODO lo que se
        // recoge ese día, así que una cesta ajena cambiaría el número.
        $fecha = '2099-06-05';
        $sufijo = uniqid();
        $picks = $em->getRepository(WeeklyBasketStatus::class)->find(1);
        $biweekly = $em->getRepository(BasketShare::class)->find(BasketShare::ID_BIWEEKLY);

        $basket = (new Basket())->setDate(new \DateTime($fecha))->setWeek(23)->setAmount(1);
        $em->persist($basket);
        $node = (new Node())->setName('Torremocha TEST ' . $sufijo)->setDeliveryWeekday(5);
        $grupo = (new WeeklyBasketGroup())->setName('Bustarviejo')->setColor('#5a8fbf')->setNode($node);
        $em->persist($node);
        $em->persist($grupo);

        // Tiene correo (así que se le PODÍA avisar) pero no cuenta de acceso, y
        // el correo está apagado por defecto en db_test: no le llega nada.
        $wb = $this->makeWeeklyBasket($em, $basket, $biweekly, $picks, $grupo, $fecha, 'SinAvisoSocix ' . $sufijo);

        // Un apunte cualquiera ANTERIOR, para que la cobertura tenga desde
        // dónde juzgar. Sin ningún apunte en la tabla, un reparto se considera
        // anterior al registro y —con razón— no se afirma nada de él; entonces
        // este test pasaría por el motivo equivocado.
        $frontera = (new EmittedEffect())
            ->setKind(PickupReminderMailer::EFFECT_KIND)
            ->setReference('partner-0')
            ->setOccurredOn(new \DateTimeImmutable('2099-06-01'))
            ->setTarget('frontera@test.org');
        $em->persist($frontera);
        $em->flush();

        try {
            $tester = $this->commandTester();
            $tester->execute(['--date' => $fecha]);
            $display = $tester->getDisplay();

            $this->assertStringContainsString('NO se les ha avisado', $display, 'La tarea tiene que delatar el hueco.');
        } finally {
            $partner = $wb->getPartner();
            $em->remove($wb);
            $em->remove($frontera);
            $em->flush();
            $em->remove($partner);
            $em->remove($grupo);
            $em->remove($node);
            $em->remove($basket);
            $em->flush();
        }
    }

    /**
     * Un reparto CANCELADO por una excepción de calendario no genera aviso, aun
     * cuando su WeeklyBasket siga viva (status "recoge") con delivery_date en el
     * día cancelado — el generador no materializa la cancelación. Es el modo de
     * fallo peligroso: avisar de un reparto que no existe.
     */
    public function testDryRunExcluyeRepartoCancelado(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $sufijo = uniqid();
        $cancelado = '2099-08-07';
        $picks = $em->getRepository(WeeklyBasketStatus::class)->find(1);
        $biweekly = $em->getRepository(BasketShare::class)->find(BasketShare::ID_BIWEEKLY);

        $basket = (new Basket())->setDate(new \DateTime($cancelado))->setWeek(32)->setAmount(1);
        $em->persist($basket);

        // Cierre GLOBAL (node null) que CANCELA (shiftedDate null) ese ciclo.
        $cierre = (new DeliveryException())->setBasket($basket);
        $em->persist($cierre);

        $node = (new Node())->setName('Torremocha TEST ' . $sufijo)->setDeliveryWeekday(5);
        $grupo = (new WeeklyBasketGroup())->setName('Bustarviejo')->setColor('#5a8fbf')->setNode($node);
        $em->persist($node);
        $em->persist($grupo);

        $nombre = 'CanceladoSocix ' . $sufijo;
        $wb = $this->makeWeeklyBasket($em, $basket, $biweekly, $picks, $grupo, $cancelado, $nombre);
        $em->flush();

        $tester = $this->commandTester();
        $tester->execute(['--dry-run' => true, '--date' => $cancelado]);
        $display = $tester->getDisplay();

        $this->assertStringNotContainsString($nombre, $display, 'Un reparto cancelado no debe generar aviso.');
        $this->assertStringContainsString('Nadie', $display, 'Sin destinatarios tras filtrar el cancelado.');

        // Limpieza.
        $partner = $wb->getPartner();
        $em->remove($wb);
        $em->flush();
        $em->remove($partner);
        $em->remove($cierre);
        $em->remove($grupo);
        $em->remove($node);
        $em->remove($basket);
        $em->flush();
    }

    /**
     * EL CRITERIO DE ACEPTACIÓN de la idempotencia: lanzar el recordatorio dos
     * veces seguidas no manda ni un correo repetido.
     *
     * Es lo que permite que el planificador reintente sin miedo — y sin esto, un
     * tick horario o dos relojes en paralelo reenviarían el aviso a los mismos
     * socixs una y otra vez. Aquí se ejecuta de verdad (no dry-run), con los dos
     * interruptores de envío encendidos y el transporte de correo a `null` del
     * entorno de test.
     */
    public function testNoReenviaEnUnaSegundaPasada(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $settings = static::getContainer()->get(AppSettings::class);

        $fecha = '2099-09-04';
        $sufijo = uniqid();
        $picks = $em->getRepository(WeeklyBasketStatus::class)->find(1);
        $biweekly = $em->getRepository(BasketShare::class)->find(BasketShare::ID_BIWEEKLY);

        $basket = (new Basket())->setDate(new \DateTime($fecha))->setWeek(36)->setAmount(1);
        $em->persist($basket);
        $node = (new Node())->setName('Torremocha TEST ' . $sufijo)->setDeliveryWeekday(5);
        $grupo = (new WeeklyBasketGroup())->setName('Bustarviejo')->setColor('#5a8fbf')->setNode($node);
        $em->persist($node);
        $em->persist($grupo);

        $wb = $this->makeWeeklyBasket($em, $basket, $biweekly, $picks, $grupo, $fecha, 'IdempotenteSocix ' . $sufijo);
        $em->flush();

        $settings->setBool(AppSettings::EMAIL_ENABLED, true);
        $settings->setBool(AppSettings::EMAIL_PICKUP_REMINDER, true);

        try {
            $tester = $this->commandTester();

            $tester->execute(['--date' => $fecha]);
            $primera = $tester->getDisplay();
            $this->assertStringContainsString('Enviados 1 email(s)', $primera);

            $tester->execute(['--date' => $fecha]);
            $segunda = $tester->getDisplay();
            $this->assertStringContainsString('Enviados 0 email(s)', $segunda, 'La segunda pasada no debe reenviar nada.');
            $this->assertStringContainsString('1 ya estaban avisados', $segunda);

            // La vía de rescate: --resend repite a propósito lo que ya constaba,
            // para cuando se sabe que un correo no llegó.
            $tester->execute(['--date' => $fecha, '--resend' => true]);
            $this->assertStringContainsString('Enviados 1 email(s)', $tester->getDisplay(), '--resend debe repetir el aviso ya emitido.');
        } finally {
            $partner = $wb->getPartner();
            $em->remove($wb);
            $em->flush();
            $em->remove($partner);
            $em->remove($grupo);
            $em->remove($node);
            $em->remove($basket);
            foreach ($em->getRepository(EmittedEffect::class)->findBy(['kind' => PickupReminderMailer::EFFECT_KIND]) as $effect) {
                $em->remove($effect);
            }
            foreach ([AppSettings::EMAIL_ENABLED, AppSettings::EMAIL_PICKUP_REMINDER] as $key) {
                $setting = $em->getRepository(Setting::class)->findOneBy(['name' => $key]);
                if ($setting !== null) {
                    $em->remove($setting);
                }
            }
            $em->flush();
        }
    }

    /**
     * Una fecha imposible en --date (2026-02-30, que new \DateTimeImmutable()
     * aceptaría rodando a marzo) se rechaza con error, en vez de avisar de un día
     * equivocado en silencio. Se usa --dry-run para saltar el gate de email y
     * llegar a la resolución de la fecha.
     */
    public function testDateInvalidaDevuelveError(): void
    {
        self::bootKernel();
        $tester = $this->commandTester();

        $exit = $tester->execute(['--dry-run' => true, '--date' => '2026-02-30']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('inválida', $tester->getDisplay());
    }

    private function makeWeeklyBasket(
        EntityManagerInterface $em,
        Basket $basket,
        BasketShare $share,
        WeeklyBasketStatus $status,
        WeeklyBasketGroup $group,
        string $deliveryDate,
        string $partnerName,
    ): WeeklyBasket {
        $partner = (new Partner())->setName($partnerName)->setEmail(strtolower(str_replace(' ', '', $partnerName)) . '@test.org');
        $em->persist($partner);

        $wb = (new WeeklyBasket())
            ->setPartner($partner)
            ->setBasket($basket)
            ->setBasketShare($share)
            ->setWeeklyBasketStatus($status)
            ->setWeeklyBasketGroup($group)
            ->setDeliveryDate(new \DateTime($deliveryDate))
            ->setAmount(1);
        $em->persist($wb);

        return $wb;
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:send-pickup-reminders'));
    }
}
