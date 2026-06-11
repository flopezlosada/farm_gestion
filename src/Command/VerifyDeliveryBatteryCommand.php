<?php

namespace App\Command;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\EggPeriod;
use App\Entity\BasketShare;
use App\Entity\DeliveryException;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerNodeOverride;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketItem;
use App\Repository\NodeRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Service\Delivery\BiweeklyCohortResolver;
use App\Service\Delivery\DeliveryModeResolver;
use App\Service\Delivery\ExtraBasketEditor;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\NodeDeliverySheet;
use App\Service\Delivery\DeliveryCalendarProjector;
use App\Service\Delivery\Invariant\DeliveryInvariant;
use App\Service\Delivery\Invariant\InvariantSuite;
use App\Service\Delivery\PickupRelocator;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Delivery\WeekRematerializer;
use App\Service\Partner\BasketModalityChanger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Arnés de verificación de la operativa de reparto: aplica una BATERÍA de escenarios
 * (cambios de modalidad en todas las direcciones, baja, cambio puntual de día/no-recoge,
 * cesta compartida) sobre datos reales y comprueba que el resultado sale bien en el
 * MODELO INTERNO (WeeklyBasket) y en el LISTADO/PDF ({@see NodeDeliverySheet}).
 *
 * Pensado para re-probar de un golpe tras tocar la generación (p. ej. la materialización
 * tardía): un escenario fallido apunta a una regresión. Cada escenario es independiente,
 * sobre su PROPIO mes futuro sin generar y su PROPIO socio (elegido dinámicamente por
 * criterio, no por id), para no interferir entre sí.
 *
 * MUTA la BBDD (parte PBS, borra WB, inserta shifts): correr SIEMPRE sobre un clon
 * desechable, nunca sobre golden:
 *   bin/db-play-reset && \
 *   ddev exec "DATABASE_URL='mysql://db:db@db:3306/db_play?serverVersion=8.0' \
 *              php bin/console app:verify-delivery-battery --force"
 */
#[AsCommand(
    name: 'app:verify-delivery-battery',
    description: 'Batería de verificación del reparto (modalidad/baja/shift/compartida) en modelo y listado.'
)]
class VerifyDeliveryBatteryCommand extends Command
{
    private const SEMANAL = 1;
    private const QUINCENAL = 2;
    private const MENSUAL = 3;
    private const COMP_SEMANAL = 4;
    private const ONLY_EGG = 5;
    private const COMP_QUINCENAL = 6;
    /** egg_period: huevo mensual (cf EggDeliveryResolver). */
    private const EGG_PERIOD_MENSUAL = 3;

    private SymfonyStyle $io;
    /** @var int[] partners ya usados por algún escenario (no reutilizar). */
    private array $usedPartners = [];
    /** Offset rotatorio de meses ya repartidos a escenarios. */
    private int $monthCursor = 0;
    /** @var list<array{name:string, ok:bool, detail:string}> */
    private array $results = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BasketModalityChanger $modalityChanger,
        private readonly WeeklyBasketGenerator $generator,
        private readonly NodeDeliverySheet $sheet,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly BiweeklyCohortResolver $cohortResolver,
        private readonly PartnerBasketShareRepository $shareRepo,
        private readonly NodeRepository $nodeRepo,
        private readonly ExtraBasketEditor $extraBasketEditor,
        private readonly PickupRelocator $relocator,
        private readonly DeliveryModeResolver $modeResolver,
        private readonly DeliveryCalendarProjector $calendarProjector,
        private readonly InvariantSuite $invariantSuite,
        private readonly WeekRematerializer $weekRematerializer,
    ) {
        parent::__construct();
    }

    /**
     * Fecha física en la que el CALENDARIO del socio coloca su entrega de $basket (la del slot
     * no saltado de ese ciclo), o null si ese mes no le proyecta entrega ahí. Sirve para
     * comprobar que el calendario y el listado concuerdan tras un traslado de nodo — el hueco
     * que produjo el bug listado↔calendario (el proyector no aplicaba PartnerNodeOverride).
     */
    private function calendarSlotDateFor(Partner $partner, Basket $basket): ?\DateTimeInterface
    {
        $date = $basket->getDate();
        foreach ($this->calendarProjector->projectMonth($partner, (int) $date->format('Y'), (int) $date->format('n')) as $slot) {
            if ($slot['basket']->getId() === $basket->getId() && !$slot['skipped']) {
                return $slot['date'];
            }
        }

        return null;
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Confirma que se corre sobre un clon desechable (muta la BBDD).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        if (!$input->getOption('force')) {
            $this->io->error('Este comando MUTA la BBDD. Corre sobre un clon desechable (db_play) y pasa --force.');
            return Command::FAILURE;
        }
        // --force confirma la intención; esto verifica el hecho (clon de verdad, no golden/sandbox).
        DatabaseGuard::assertDisposable($this->em->getConnection(), ['play', 'battery']);

        $this->io->title('Batería de verificación de reparto');

        // Direcciones de cambio de modalidad (las 6 combinaciones).
        $this->modalityScenario('Quincenal → Mensual', self::QUINCENAL, self::MENSUAL);
        $this->modalityScenario('Quincenal → Semanal', self::QUINCENAL, self::SEMANAL);
        $this->modalityScenario('Mensual → Quincenal', self::MENSUAL, self::QUINCENAL);
        $this->modalityScenario('Mensual → Semanal', self::MENSUAL, self::SEMANAL);
        $this->modalityScenario('Semanal → Quincenal', self::SEMANAL, self::QUINCENAL);
        $this->modalityScenario('Semanal → Mensual', self::SEMANAL, self::MENSUAL);

        // Baja con fecha de fin futura: reparte hasta la fecha, no después.
        $this->bajaScenario();

        // Cambio puntual de día: mover y no-recoge.
        $this->shiftMoveScenario();
        $this->shiftSkipScenario();

        // Mover CON huevos: la composición entera (verdura + docenas) viaja al destino
        // (caso Franco — los huevos se quedaban en el viernes de cohorte).
        $this->shiftMoveWithEggsScenario();

        // Nodo biweekly (Madrid, miércoles): reparte solo en sus ciclos activos.
        $this->biweeklyNodeScenario();

        // Festivo (cancelación global): ese ciclo no reparte nadie.
        $this->festivoScenario();

        // Cierre que DESPLAZA la cadencia de un nodo biweekly: la semana nueva (sin
        // piedra propia pero con el basket materializado por otro nodo) debe DIBUJARSE,
        // no salir vacía. Es el hueco que dejó pasar la decisión piedra-vs-dibujo global.
        $this->biweeklyCierreShiftScenario();

        // Excepciones sobre semanas YA generadas: la piedra se re-materializa
        // (WeekRematerializer, el flujo del alta de excepción del admin).
        $this->cierreSemanaGeneradaScenario();
        $this->cierreNodoSemanaGeneradaScenario();
        $this->trasladoSemanaGeneradaScenario();

        // Cesta compartida quincenal (bs=6): media cesta en el bloque compartidas.
        $this->sharedQuincenalScenario();

        // Baja de media pareja: no debe crashear y rompe el vínculo (regresión be0ec62).
        $this->sharedBajaScenario();

        // SANTOS: cesta mensual + huevo semanal → cesta+huevo el día mensual, solo-huevo el resto.
        $this->santosScenario();
        $this->miriamScenario();

        // Orden mensual PEGAJOSO: un cierre global en la 1ª semana del mes no
        // desplaza al mensual de la 2ª (bajo renumeración caería en la 3ª).
        $this->mensualStickyScenario();

        // Materialización tardía: el listado DIBUJADO al vuelo (proyección, sin
        // persistir) debe ser idéntico al MATERIALIZADO para la misma semana.
        $this->projectionEquivalenceScenario();

        // Igual, pero CON un intent por componente (mover solo la verdura): blinda
        // que la proyección aplique el mover-componente como el materializado.
        $this->projectionEquivalenceComponentMoveScenario();

        // Cesta extra puntual: subir la cantidad de quien ya recoge, y dar cesta a
        // quien no le tocaba. Ambas sobre una única entrega; sobrevive a un regenerado.
        $this->extraBasketSumScenario();
        $this->extraBasketNewScenario();

        // Cesta extra como OVERRIDE: sobre semana DIBUJADA (la proyección la suma y al congelar
        // entra en piedra == dibujo), a un NO-SUSCRIPTOR (sección "Cestas extra"), y DESHACER.
        $this->extraDrawnWeekScenario();
        $this->extraNonSubscriberScenario();
        $this->removeExtraScenario();

        // Traslado puntual de lugar: un socio recoge esa semana en otro nodo.
        $this->relocateNodeScenario();

        // Cambio de nodo como OVERRIDE sobre una semana DIBUJADA (futura, sin generar):
        // el override mueve dónde recoge en la proyección, sin piedra. Blinda el modelo de
        // overrides (docs/redesign/modelo-overrides-reparto.md): el socio sale del dibujo de
        // su nodo de casa y aparece en el destino, en una semana que NO está generada.
        $this->nodeOverrideDrawnScenario();

        // Volver al nodo de casa: deshacer un traslado (returnHome) devuelve al socio a su nodo.
        $this->returnHomeScenario();

        $this->report();

        // Juicio transversal: tras ejercitar los escenarios, las LEYES del dominio
        // deben cumplirse sobre todo el clon — caza efectos colaterales que ningún
        // escenario mira (su aserción es local; los invariantes son globales).
        $invariantErrors = $this->reportInvariants();

        return $invariantErrors === 0
            && array_reduce($this->results, fn (int $c, array $r) => $c + ($r['ok'] ? 0 : 1), 0) === 0
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    /**
     * Corre la suite de invariantes sobre el estado que dejaron los escenarios
     * y la reporta. Devuelve el número de violaciones duras (los avisos no
     * tumban la batería).
     *
     * @return int Violaciones con severidad error.
     */
    private function reportInvariants(): int
    {
        $this->io->section('Invariantes del dominio sobre el clon ejercitado');

        $errors = 0;
        foreach ($this->invariantSuite->run(new \DateTimeImmutable('today')) as $result) {
            if ($result['violations'] === []) {
                $this->io->writeln(sprintf(' <info>✓</info> %s %s', $result['code'], $result['name']));
                continue;
            }
            $isWarning = $result['severity'] === DeliveryInvariant::SEVERITY_WARNING;
            if (!$isWarning) {
                $errors += count($result['violations']);
            }
            $this->io->writeln(sprintf(
                ' <%s>%s</> %s %s — %d',
                $isWarning ? 'comment' : 'error',
                $isWarning ? '⚠' : '✗',
                $result['code'],
                $result['name'],
                count($result['violations'])
            ));
            foreach (array_slice($result['violations'], 0, 10) as $violation) {
                $this->io->writeln('      · ' . $violation);
            }
            if (count($result['violations']) > 10) {
                $this->io->writeln(sprintf('      … y %d más (detalle: app:verify-delivery-invariants sobre el clon).', count($result['violations']) - 10));
            }
        }

        return $errors;
    }

    // ---------------------------------------------------------------- escenarios

    private function modalityScenario(string $label, int $fromShare, int $toShare): void
    {
        $partner = $this->pickPartner($fromShare, false);
        if ($partner === null) {
            $this->skip($label, sprintf('sin socio libre de modalidad %d sin huevos', $fromShare));
            return;
        }
        $month = $this->nextMonth();
        if ($month === null) {
            $this->skip($label, 'sin mes futuro sin generar');
            return;
        }

        $effective = $month['first']->getDate();
        $old = $this->shareRepo->findActiveForPartner($partner, (clone $effective)->modify('-1 day'));

        // 1) Generar el mes ANTES del cambio (estado "ya listado", el caso peludo).
        foreach ($month['baskets'] as $b) {
            $this->generator->generateForBasket($b);
        }
        // 2) Cambio de modalidad con histórico + reconciliación de lo ya generado.
        $new = $this->buildTargetShare($old, $toShare, $month);
        $this->modalityChanger->applyChange($new, $effective, 'verif');
        $this->generator->reconcilePartnerFrom($partner, $effective);

        // 3) Comprobar: las entregas del mes son de la modalidad NUEVA y caen en los
        //    viernes correctos según la cadencia destino.
        $expected = $this->expectedDeliveryDates($toShare, $month, $new->getDeliveryGroup());
        $got = $this->partnerDeliveryBasketIds($partner, $month['baskets']);
        $internalOk = $got === $expected;

        // Plano listado: el socio sale en NodeDeliverySheet con el código de la nueva
        // modalidad en cada viernes esperado (y no en los demás).
        $listadoOk = $this->listadoMatches($partner, $month, $expected, $toShare, $new->getDeliveryGroup());

        $this->record(
            $label,
            $internalOk && $listadoOk,
            sprintf(
                'socio %d · esperados [%s] · obtenidos [%s] · listado=%s',
                $partner->getId(),
                implode(',', $expected),
                implode(',', $got),
                $listadoOk ? 'ok' : 'MAL',
            ),
        );
    }

    private function bajaScenario(): void
    {
        $partner = $this->pickPartner(self::SEMANAL, false) ?? $this->pickPartner(self::SEMANAL, true);
        if ($partner === null) {
            $this->skip('Baja (fin futuro)', 'sin socio semanal libre');
            return;
        }
        $month = $this->nextMonth();
        if ($month === null || count($month['baskets']) < 3) {
            $this->skip('Baja (fin futuro)', 'mes futuro insuficiente');
            return;
        }

        // Baja efectiva el 2º viernes del mes: reparte 1º y 2º, no del 3º en adelante.
        $endBasket = $month['baskets'][1];
        $endDate = $endBasket->getDate();
        $share = $this->shareRepo->findActiveForPartner($partner, $endDate);
        $share->setEndDate(\DateTime::createFromInterface($endDate));
        $this->em->flush();

        foreach ($month['baskets'] as $b) {
            $this->generator->generateForBasket($b);
        }

        $got = $this->partnerDeliveryBasketIds($partner, $month['baskets']);
        $expected = array_values(array_filter(
            array_map(static fn (Basket $b) => $b->getId(), $month['baskets']),
            fn (int $id, int $i) => $month['baskets'][$i]->getDate() <= $endDate,
            ARRAY_FILTER_USE_BOTH,
        ));
        $this->record('Baja (fin futuro)', $got === $expected, sprintf(
            'socio %d · fin %s · reparte hasta el fin [%s] · obtenidos [%s]',
            $partner->getId(), $endDate->format('Y-m-d'), implode(',', $expected), implode(',', $got),
        ));
    }

    private function shiftMoveScenario(): void
    {
        $partner = $this->pickPartner(self::QUINCENAL, false);
        if ($partner === null) { $this->skip('Shift mover', 'sin quincenal libre'); return; }
        $month = $this->nextMonth();
        if ($month === null || count($month['baskets']) < 3) { $this->skip('Shift mover', 'mes insuficiente'); return; }

        // Día de reparto del socio (su cohorte) → moverlo a un día donde NO repartiría.
        $deliveryDays = $this->expectedDeliveryDates(self::QUINCENAL, $month, $this->cohortOfPartner($partner));
        $freeDays = array_values(array_diff(array_map(static fn (Basket $b) => $b->getId(), $month['baskets']), $deliveryDays));
        if ($deliveryDays === [] || $freeDays === []) { $this->skip('Shift mover', 'sin par origen/destino'); return; }

        $from = $this->basketById($deliveryDays[0]);
        $to = $this->basketById($freeDays[0]);
        $this->insertShift($partner, $from, $to);
        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $got = $this->partnerDeliveryBasketIds($partner, $month['baskets']);
        $ok = !in_array($from->getId(), $got, true) && in_array($to->getId(), $got, true);
        $this->record('Shift mover', $ok, sprintf(
            'socio %d · %d→%d · obtenidos [%s] (origen fuera, destino dentro)',
            $partner->getId(), $from->getId(), $to->getId(), implode(',', $got),
        ));
    }

    private function shiftSkipScenario(): void
    {
        $partner = $this->pickPartner(self::SEMANAL, false) ?? $this->pickPartner(self::SEMANAL, true);
        if ($partner === null) { $this->skip('Shift no-recoge', 'sin semanal libre'); return; }
        $month = $this->nextMonth();
        if ($month === null || count($month['baskets']) < 2) { $this->skip('Shift no-recoge', 'mes insuficiente'); return; }

        $skip = $month['baskets'][0];
        $this->insertShift($partner, $skip, null);
        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $got = $this->partnerDeliveryBasketIds($partner, $month['baskets']);
        $rest = array_map(static fn (Basket $b) => $b->getId(), array_slice($month['baskets'], 1));
        $ok = !in_array($skip->getId(), $got, true) && $got === $rest;
        $this->record('Shift no-recoge', $ok, sprintf(
            'socio %d · no-recoge %d · obtenidos [%s] (ausente ese viernes, presente el resto)',
            $partner->getId(), $skip->getId(), implode(',', $got),
        ));
    }

    /**
     * Mover una entrega CON huevos: la composición entera viaja al destino (caso
     * Franco). Elige un semanal con huevo SEMANAL (así el origen lleva docenas
     * seguro) y comprueba que el destino materializa verdura Y docenas, y que el
     * origen no conserva entrega viva.
     */
    private function shiftMoveWithEggsScenario(): void
    {
        $label = 'Shift mover CON huevos (composición viaja — caso Franco)';

        // Buscar uno de huevo SEMANAL (pickPartner solo filtra "tiene huevos"): los
        // candidatos descartados se devuelven al pool para no vaciarlo de cara a
        // los escenarios siguientes.
        $partner = null;
        $dozens = 0.0;
        $burned = [];
        while (($candidate = $this->pickPartner(self::SEMANAL, true)) !== null) {
            $pbs = $this->shareRepo->findActiveForPartner($candidate);
            if ($pbs?->getEggPeriod()?->getId() === 1 && $pbs->getEggAmount() !== null) {
                $partner = $candidate;
                $dozens = $pbs->getEggAmount()->getDozens();
                break;
            }
            $burned[] = $candidate->getId();
        }
        $this->usedPartners = array_values(array_diff($this->usedPartners, $burned));
        if ($partner === null) { $this->skip($label, 'sin semanal con huevo semanal libre'); return; }

        $month = $this->nextMonth();
        if ($month === null || count($month['baskets']) < 2) { $this->skip($label, 'mes insuficiente'); return; }

        $from = $month['baskets'][0];
        $to = $month['baskets'][1];
        $this->insertShift($partner, $from, $to);
        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $destEggs = $this->componentAmountFor($partner, $to, BasketComponent::ID_EGGS);
        $destVeg = $this->componentAmountFor($partner, $to, BasketComponent::ID_VEGETABLES);
        $got = $this->partnerDeliveryBasketIds($partner, $month['baskets']);

        $ok = !in_array($from->getId(), $got, true)
            && in_array($to->getId(), $got, true)
            && abs($destEggs - $dozens) < 0.01
            && $destVeg > 0;
        $this->record($label, $ok, sprintf(
            'socio %d · %d→%d · destino verdura=%s (debe >0) · docenas=%s (deben %s) · origen fuera=%s',
            $partner->getId(), $from->getId(), $to->getId(),
            $destVeg, $destEggs, $dozens,
            in_array($from->getId(), $got, true) ? 'NO' : 'sí',
        ));
    }

    /**
     * Cierre GLOBAL sobre una semana YA generada: el flujo del admin
     * (WeekRematerializer::reconcileStone) debe dejar la semana cerrada VACÍA y
     * re-materializar las posteriores generadas (la alternancia se desplaza),
     * sin perderlas.
     */
    private function cierreSemanaGeneradaScenario(): void
    {
        $label = 'Cierre de semana YA generada (re-materializa)';
        $month = $this->nextMonth();
        if ($month === null || count($month['baskets']) < 2) { $this->skip($label, 'mes insuficiente'); return; }

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $week = $month['baskets'][0];
        $next = $month['baskets'][1];
        $before = $wbRepo->count(['basket' => $week]);
        $nextBefore = $wbRepo->count(['basket' => $next]);
        if ($before === 0 || $nextBefore === 0) { $this->skip($label, 'el mes no materializó entregas'); return; }

        $exception = new DeliveryException();
        $exception->setBasket($week);
        $exception->setNode(null);
        $exception->setShiftedDate(null);
        $exception->setNotes('verif: cierre sobre semana generada');
        $this->em->persist($exception);
        $this->em->flush();

        $this->weekRematerializer->reconcileStone($week, true);

        $after = $wbRepo->count(['basket' => $week]);
        $nextAfter = $wbRepo->count(['basket' => $next]);

        $ok = $after === 0 && $nextAfter > 0;
        $this->record($label, $ok, sprintf(
            'semana %d: %d→%d entregas (debe 0) · posterior %d: %d→%d (debe >0, regenerada)',
            $week->getId(), $before, $after, $next->getId(), $nextBefore, $nextAfter,
        ));
    }

    /**
     * Cierre de UN NODO sobre una semana YA generada: solo las entregas de ese
     * nodo desaparecen; el resto de nodos conserva su listado.
     */
    private function cierreNodoSemanaGeneradaScenario(): void
    {
        $label = 'Cierre de nodo en semana generada (solo ese nodo fuera)';
        $node = $this->nodeRepo->findOneBy(['cadence' => Node::CADENCE_WEEKLY]);
        if ($node === null) { $this->skip($label, 'sin nodo semanal'); return; }
        $month = $this->nextMonth();
        if ($month === null || $month['baskets'] === []) { $this->skip($label, 'mes insuficiente'); return; }

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $week = $month['baskets'][0];
        $nodeBefore = count($wbRepo->findForNodeAndBasket($node, $week));
        $totalBefore = $wbRepo->count(['basket' => $week]);
        if ($nodeBefore === 0 || $totalBefore === $nodeBefore) { $this->skip($label, 'sin reparto mixto de nodos esa semana'); return; }

        $exception = new DeliveryException();
        $exception->setBasket($week);
        $exception->setNode($node);
        $exception->setShiftedDate(null);
        $exception->setNotes('verif: cierre de nodo sobre semana generada');
        $this->em->persist($exception);
        $this->em->flush();

        $this->weekRematerializer->reconcileStone($week, false);

        $nodeAfter = count($wbRepo->findForNodeAndBasket($node, $week));
        $totalAfter = $wbRepo->count(['basket' => $week]);

        $ok = $nodeAfter === 0 && $totalAfter > 0;
        $this->record($label, $ok, sprintf(
            'semana %d · nodo %d: %d→%d entregas (debe 0) · total: %d→%d (resto vivo)',
            $week->getId(), $node->getId(), $nodeBefore, $nodeAfter, $totalBefore, $totalAfter,
        ));
    }

    /**
     * Traslado (festivo con fecha movida) sobre una semana YA generada: la piedra
     * se re-materializa con la delivery_date trasladada en todas las entregas.
     */
    private function trasladoSemanaGeneradaScenario(): void
    {
        $label = 'Traslado de festivo en semana generada (fechas recalculadas)';
        // Se asevera sobre el nodo SEMANAL: los nodos legacy sin delivery_weekday
        // caen al viernes-ciclo por diseño y ensuciarían una aserción global.
        $node = $this->nodeRepo->findOneBy(['cadence' => Node::CADENCE_WEEKLY]);
        if ($node === null || $node->getDeliveryWeekday() === null) { $this->skip($label, 'sin nodo semanal con día'); return; }
        $month = $this->nextMonth();
        if ($month === null || $month['baskets'] === []) { $this->skip($label, 'mes insuficiente'); return; }

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $week = $month['baskets'][0];
        if (count($wbRepo->findForNodeAndBasket($node, $week)) === 0) { $this->skip($label, 'semana sin entregas del nodo'); return; }

        $shifted = \DateTimeImmutable::createFromInterface($week->getDate())->modify('-1 day');
        $exception = new DeliveryException();
        $exception->setBasket($week);
        $exception->setNode(null);
        $exception->setShiftedDate($shifted);
        $exception->setNotes('verif: traslado sobre semana generada');
        $this->em->persist($exception);
        $this->em->flush();

        $this->weekRematerializer->reconcileStone($week, false);

        $dates = array_values(array_unique(array_map(
            static fn (WeeklyBasket $wb): string => $wb->getDeliveryDate()?->format('Y-m-d') ?? 'NULL',
            $wbRepo->findForNodeAndBasket($node, $week),
        )));

        $ok = $dates === [$shifted->format('Y-m-d')];
        $this->record($label, $ok, sprintf(
            'semana %d trasladada a %s · fechas en piedra del nodo %d: [%s] (debe solo la trasladada)',
            $week->getId(), $shifted->format('Y-m-d'), $node->getId(), implode(',', $dates),
        ));
    }

    /**
     * Cantidad materializada de un componente en la entrega de un socio esa semana
     * (0 si no tiene entrega).
     */
    private function componentAmountFor(Partner $partner, Basket $basket, int $componentId): float
    {
        $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket, 'partner' => $partner]);
        if ($wb === null) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $wb]) as $item) {
            if ($item->getBasketComponent()?->getId() === $componentId) {
                $sum += (float) $item->getAmount();
            }
        }

        return $sum;
    }

    private function sharedQuincenalScenario(): void
    {
        $partner = $this->pickPartner(self::COMP_QUINCENAL, null);
        if ($partner === null) { $this->skip('Quincenal compartida', 'sin socio bs=6'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip('Quincenal compartida', 'sin mes futuro'); return; }

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $expected = $this->expectedDeliveryDates(self::COMP_QUINCENAL, $month, $this->cohortOfPartner($partner));
        $rows = $this->wbRows($partner, $month['baskets']);
        // Debe materializar bs=6 con media cesta (verdura 0.50) en sus viernes de cohorte.
        $okInternal = array_keys($rows) === $expected
            && !array_filter($rows, fn (array $r) => $r['bs'] !== self::COMP_QUINCENAL || $r['verdura'] !== '0.50');
        $listadoOk = $this->listadoMatches($partner, $month, $expected, self::COMP_QUINCENAL, $this->cohortOfPartner($partner));
        $this->record('Quincenal compartida', $okInternal && $listadoOk, sprintf(
            'socio %d · esperados [%s] · bs/verdura ok=%s · listado=%s',
            $partner->getId(), implode(',', $expected), $okInternal ? 'sí' : 'NO', $listadoOk ? 'ok' : 'MAL',
        ));
    }

    /**
     * Nodo biweekly (Madrid: Cascorro/Midori, miércoles): el socio recoge SÓLO en los
     * ciclos donde el nodo reparte. En un nodo biweekly manda la cadencia del NODO, no
     * la cohorte A/B del socio (de hecho sus PBS tienen delivery_group NULL). El día
     * físico es miércoles, pero el WeeklyBasket se cuelga del Basket-ciclo, así que
     * aseveramos sobre ciclos; el oráculo de "qué ciclos reparte" lo da NodeDeliveryDate
     * (que ya resuelve día del nodo + ancla + cierres), nunca lo derivamos aquí.
     */
    private function biweeklyNodeScenario(): void
    {
        $partner = $this->pickPartner(self::QUINCENAL, null, Node::CADENCE_BIWEEKLY);
        if ($partner === null) { $this->skip('Nodo biweekly (Madrid)', 'sin quincenal activo en nodo biweekly'); return; }
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        if ($node === null) { $this->skip('Nodo biweekly (Madrid)', 'el socio elegido no tiene nodo'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip('Nodo biweekly (Madrid)', 'sin mes futuro sin generar'); return; }

        // Ciclos del mes donde el nodo reparte de verdad (descontando cadencia/excepciones).
        $expected = array_values(array_map(
            static fn (Basket $b) => $b->getId(),
            array_filter($month['baskets'], fn (Basket $b) => $this->nodeDeliveryDate->deliversInBasket($b, $node)),
        ));

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $got = $this->partnerDeliveryBasketIds($partner, $month['baskets']);
        $internalOk = $got === $expected;
        $listadoOk = $this->listadoMatches($partner, $month, $expected, self::QUINCENAL, null);
        $this->record('Nodo biweekly (Madrid)', $internalOk && $listadoOk, sprintf(
            'socio %d · nodo %d (miércoles, ancla %s) · reparte ciclos [%s] · obtenidos [%s] · listado=%s',
            $partner->getId(), $node->getId(),
            $node->getAnchorDate()?->format('Y-m-d') ?? '?',
            implode(',', $expected), implode(',', $got), $listadoOk ? 'ok' : 'MAL',
        ));
    }

    /**
     * Festivo / cierre general (DeliveryException global: node=null, shiftedDate=null):
     * ese ciclo no reparte NADIE. Es la garantía operativa de "festivo = sin reparto":
     * al generar el ciclo cancelado no se materializa ninguna cesta y el listado del
     * nodo sale vacío, mientras el viernes adyacente queda normal (control). El
     * generador hereda el cierre vía NodeDeliveryDate (physicalDateFor → null).
     *
     * No comprueba el DESPLAZAMIENTO de la cadencia biweekly por el cierre: esa
     * matemática ya la cubre NodeDeliveryDateTest (testCierreGlobalDesplazaLaCadenciaBiweekly).
     */
    private function festivoScenario(): void
    {
        $label = 'Festivo (cancelación global)';
        $month = $this->nextMonth();
        if ($month === null || count($month['baskets']) < 2) { $this->skip($label, 'mes futuro insuficiente'); return; }

        $festivo = $month['baskets'][0];
        $control = $month['baskets'][1];

        $exception = new DeliveryException();
        $exception->setBasket($festivo);
        $exception->setNode(null);          // global: aplica a todos los nodos
        $exception->setShiftedDate(null);   // sin fecha destino: cancelado, no trasladado
        $exception->setNotes('verif: festivo');
        $this->em->persist($exception);
        $this->em->flush();

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $festivoWb = $wbRepo->count(['basket' => $festivo]);
        $controlWb = $wbRepo->count(['basket' => $control]);

        // Plano listado: el nodo semanal (Torremocha) debe salir vacío el festivo.
        $weeklyNode = $this->nodeRepo->findOneBy(['cadence' => Node::CADENCE_WEEKLY]);
        $sheetEmpty = $weeklyNode === null || $this->sheetSignature($this->sheet->build($weeklyNode, $festivo)) === '';

        $ok = $festivoWb === 0 && $controlWb > 0 && $sheetEmpty;
        $this->record($label, $ok, sprintf(
            'festivo basket %d (%s): %d cestas (debe 0) · control basket %d: %d cestas (>0) · listado festivo vacío=%s',
            $festivo->getId(), $festivo->getDate()->format('Y-m-d'), $festivoWb,
            $control->getId(), $controlWb, $sheetEmpty ? 'sí' : 'NO',
        ));
    }

    /**
     * Cierre global que DESPLAZA la cadencia de un nodo biweekly. Con el mes YA generado
     * (el nodo materializado en su fase vieja; el nodo semanal materializado en todas las
     * semanas), se mete un cierre en una semana de reparto del nodo. La cadencia se
     * desplaza: una semana donde el nodo antes NO repartía pasa a repartir, pero NO tiene
     * piedra propia —aunque el basket SÍ está materializado por el nodo semanal—. Esa
     * semana debe DIBUJARSE (DRAW), no leerse de piedra vacía.
     *
     * Es el hueco que dejó pasar la decisión piedra-vs-dibujo GLOBAL: el basket contaba
     * como materializado y el nodo biweekly salía vacío ("no reparte nunca tras el
     * cierre"). La batería no lo cazaba porque asevera vía NodeDeliverySheet::build, que
     * siempre lee piedra; aquí ejercitamos la decisión real (DeliveryModeResolver) y el
     * listado DIBUJADO.
     */
    private function biweeklyCierreShiftScenario(): void
    {
        $label = 'Cierre desplaza biweekly (dibuja la semana nueva)';
        $partner = $this->pickPartner(self::QUINCENAL, null, Node::CADENCE_BIWEEKLY);
        if ($partner === null) { $this->skip($label, 'sin quincenal en nodo biweekly'); return; }
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        if ($node === null) { $this->skip($label, 'el socio elegido no tiene nodo'); return; }
        $month = $this->nextMonth();
        if ($month === null || count($month['baskets']) < 2) { $this->skip($label, 'mes futuro insuficiente'); return; }

        // Semanas del mes donde el nodo reparte ANTES del cierre.
        $beforeIds = array_values(array_map(
            static fn (Basket $b) => $b->getId(),
            array_filter($month['baskets'], fn (Basket $b) => $this->nodeDeliveryDate->deliversInBasket($b, $node)),
        ));
        if ($beforeIds === []) { $this->skip($label, 'el nodo no reparte ningún ciclo del mes'); return; }

        // Generar el mes: el nodo se materializa en su fase vieja; el nodo semanal, en todas.
        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        // Cerrar (global) la PRIMERA semana de reparto del nodo → desplaza la cadencia.
        $cierre = $this->basketById($beforeIds[0]);
        $exception = new DeliveryException();
        $exception->setBasket($cierre);
        $exception->setNode(null);
        $exception->setShiftedDate(null);
        $exception->setNotes('verif: cierre desplaza biweekly');
        $this->em->persist($exception);
        $this->em->flush();

        // Semana NUEVA: el nodo reparte tras el cierre pero NO repartía antes (sin piedra).
        $shiftedIn = null;
        foreach ($month['baskets'] as $b) {
            if ($this->nodeDeliveryDate->deliversInBasket($b, $node) && !in_array($b->getId(), $beforeIds, true)) {
                $shiftedIn = $b;
                break;
            }
        }
        if ($shiftedIn === null) { $this->skip($label, 'el cierre no desplazó ninguna semana dentro del mes'); return; }

        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $nodeStone = count($wbRepo->findForNodeAndBasket($node, $shiftedIn));
        $globalMaterialized = $wbRepo->countPickedInBasket($shiftedIn) > 0;
        // Sin materialización global de la semana nueva no se reproduce el caso (la regla
        // vieja sólo fallaba porque OTRO nodo dejaba el basket como "materializado").
        if (!$globalMaterialized) { $this->skip($label, 'la semana nueva no quedó materializada por otro nodo'); return; }

        $mode = $this->modeResolver->mode($node, $shiftedIn);
        // El listado DIBUJADO (shape de la proyección) de la semana nueva contiene al socio.
        $drawn = $this->sheet->shape($this->generator->projectLinesForNode($node, $shiftedIn));
        $drawnContains = $this->sheetDataContainsPartner($drawn, $partner);
        // La semana del cierre no reparte (cancelada).
        $cierreMode = $this->modeResolver->mode($node, $cierre);

        $ok = $mode === DeliveryModeResolver::DRAW
            && $nodeStone === 0
            && $drawnContains
            && $cierreMode === DeliveryModeResolver::EMPTY;
        $this->record($label, $ok, sprintf(
            'socio %d · nodo %d · cierre basket %d · semana nueva %d · mode=%s (debe draw) · piedra nodo=%d (0) · dibujado contiene socio=%s · cierre mode=%s (debe empty)',
            $partner->getId(), $node->getId(), $cierre->getId(), $shiftedIn->getId(),
            $mode, $nodeStone, $drawnContains ? 'sí' : 'NO', $cierreMode,
        ));

        // Espejo del flujo admin: las aserciones de arriba prueban la capa resolver
        // ANTES de reconciliar (el hueco histórico); ahora se re-materializa la piedra
        // afectada como haría el alta real de la excepción, para que el clon quede
        // consistente y los invariantes lo juzguen limpio.
        $this->weekRematerializer->reconcileStone($cierre, true);
    }

    private function sharedBajaScenario(): void
    {
        $partner = $this->pickPartner(self::COMP_SEMANAL, null);
        if ($partner === null) { $this->skip('Baja media pareja', 'sin socio bs=4 con pareja'); return; }
        $pair = $partner->getSharePartner();
        if ($pair === null) { $this->skip('Baja media pareja', 'el bs=4 elegido no tiene pareja'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip('Baja media pareja', 'sin mes futuro'); return; }

        // Baja ya vencida (end_date pasada, is_active sigue 1): la coge finalizeExpiredShares.
        $share = $this->shareRepo->findActiveForPartner($partner);
        $share->setEndDate(new \DateTime('2020-01-01'));
        $this->em->flush();

        $crashed = false;
        try {
            foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }
        } catch (\Throwable $e) {
            $crashed = true;
        }

        // Espejo del flujo admin (finalize): la baja re-materializa las semanas YA
        // generadas del socio — sin esto, su piedra pre-existente (p. ej. la semana
        // operativa heredada del clon) queda huérfana del PBS finalizado (L10).
        $this->generator->reconcilePartnerFrom($partner, new \DateTime('today'));

        $this->em->refresh($share);
        $this->em->refresh($partner);
        $this->em->refresh($pair);
        $ok = !$crashed
            && !$share->getIsActive()
            && $partner->getSharePartner() === null
            && $pair->getSharePartner() === null;
        $this->record('Baja media pareja', $ok, sprintf(
            'socio %d (pareja %d) · sin crash=%s · baja inactiva=%s · vínculo roto ambos=%s',
            $partner->getId(), $pair->getId(),
            $crashed ? 'NO' : 'sí',
            $share->getIsActive() ? 'NO' : 'sí',
            ($partner->getSharePartner() === null && $pair->getSharePartner() === null) ? 'sí' : 'NO',
        ));
    }

    private function santosScenario(): void
    {
        $partner = $this->pickPartner(self::SEMANAL, true); // semanal CON huevo (suele ser semanal)
        if ($partner === null) { $this->skip('SANTOS (huevo semanal + cesta mensual)', 'sin semanal con huevo'); return; }
        $month = $this->nextMonth();
        if ($month === null || count($month['baskets']) < 3) { $this->skip('SANTOS (huevo semanal + cesta mensual)', 'mes insuficiente'); return; }

        $effective = $month['first']->getDate();
        $old = $this->shareRepo->findActiveForPartner($partner, (clone $effective)->modify('-1 day'));
        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }
        $new = $this->buildTargetShare($old, self::MENSUAL, $month); // conserva egg_period/amount del viejo
        $this->modalityChanger->applyChange($new, $effective, 'verif');
        $this->generator->reconcilePartnerFrom($partner, $effective);

        $rows = $this->wbRows($partner, $month['baskets']);
        $cestaBasket = $month['baskets'][1]->getId(); // day_month_order=2
        // El día de cesta: bs=3 con verdura. El resto de su recogida: bs=5 (solo-huevo), sin verdura.
        $cestaOk = isset($rows[$cestaBasket]) && $rows[$cestaBasket]['bs'] === self::MENSUAL && $rows[$cestaBasket]['verdura'] === '1.00';
        $eggWeeks = array_filter($rows, fn (array $r, int $bid) => $bid !== $cestaBasket, ARRAY_FILTER_USE_BOTH);
        $eggOnlyOk = $eggWeeks !== [] && !array_filter($eggWeeks, fn (array $r) => $r['bs'] !== self::ONLY_EGG || $r['verdura'] !== null);
        $this->record('SANTOS (huevo semanal + cesta mensual)', $cestaOk && $eggOnlyOk, sprintf(
            'socio %d · cesta(bs3+verdura) el %d=%s · resto solo-huevo(bs5)=%s · semanas [%s]',
            $partner->getId(), $cestaBasket, $cestaOk ? 'sí' : 'NO', $eggOnlyOk ? 'sí' : 'NO',
            implode(',', array_keys($rows)),
        ));
    }

    /**
     * MIRIAM (inverso de SANTOS): cesta QUINCENAL con huevo MENSUAL que viaja en su
     * N-ésima cesta del mes (egg_day_month_order=2). Verifica en el listado que el
     * huevo cae SOLO en su 2ª cesta y la verdura en todas. Es el caso que desde hoy
     * se configura en el formulario (antes había que tocarlo por SQL).
     */
    private function miriamScenario(): void
    {
        $label = 'MIRIAM (huevo mensual en la 2ª cesta de una quincenal)';
        $partner = $this->pickPartner(self::QUINCENAL, true);
        if ($partner === null) { $this->skip($label, 'sin quincenal con huevo'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip($label, 'sin mes futuro sin generar'); return; }

        $share = $this->shareRepo->findActiveForPartner($partner);
        if ($share === null) { $this->skip($label, 'socio sin cesta activa'); return; }
        $cohort = $share->getDeliveryGroup();
        $expectedIds = $this->expectedDeliveryDates(self::QUINCENAL, $month, $cohort);
        if (count($expectedIds) < 2) { $this->skip($label, 'el mes no le da 2 cestas a su cohorte'); return; }

        // Forzar el patrón de huevo: MENSUAL, en su 2ª cesta del mes. Espejo del
        // flujo admin (PartnerBasketShareController::edit): la corrección in situ
        // reconcilia las semanas YA generadas — sin esto, los meses que la batería
        // pre-generó conservaban la config de huevo vieja (violaciones L11/L17).
        $share->setEggPeriod($this->em->getRepository(EggPeriod::class)->find(self::EGG_PERIOD_MENSUAL));
        $share->setEggDayMonthOrder(2);
        $this->em->flush();
        $this->generator->reconcilePartnerFrom($partner, new \DateTime('today'));

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $eggBasket = $expectedIds[1]; // su 2ª cesta del mes lleva los huevos
        $verduraOk = true;
        $eggOk = true;
        $detail = [];
        foreach ($expectedIds as $bid) {
            $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $partner, 'basket' => $bid]);
            $verdura = null;
            $huevo = null;
            if ($wb !== null) {
                foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $wb]) as $item) {
                    $cid = $item->getBasketComponent()?->getId();
                    if ($cid === BasketComponent::ID_VEGETABLES) { $verdura = $item->getAmount(); }
                    if ($cid === BasketComponent::ID_EGGS) { $huevo = $item->getAmount(); }
                }
            }
            if ($verdura === null) { $verduraOk = false; }
            if (($bid === $eggBasket) !== ($huevo !== null)) { $eggOk = false; }
            $detail[] = sprintf('%d:v=%s/h=%s', $bid, $verdura ?? '-', $huevo ?? '-');
        }

        $this->record($label, $verduraOk && $eggOk, sprintf(
            'socio %d · cohorte %s · cestas [%s] · huevo solo en %d · verdura en todas=%s · huevo correcto=%s',
            $partner->getId(), $cohort ?? '-', implode(' ', $detail), $eggBasket,
            $verduraOk ? 'sí' : 'NO', $eggOk ? 'sí' : 'NO',
        ));
    }

    /**
     * Semántica PEGAJOSA del orden mensual (2026-06-12): un cierre global en
     * la 1ª semana del mes NO desplaza al mensual de la 2ª — su "2ª entrega
     * del mes" sigue siendo la misma semana de calendario (bajo la antigua
     * renumeración caería en la 3ª). La excepción se registra ANTES de generar
     * el mes; la mutación del PBS espeja el edit del admin (reconcilia).
     */
    private function mensualStickyScenario(): void
    {
        $label = 'Orden mensual pegajoso (el cierre no desplaza al de detrás)';
        $partner = $this->pickPartner(self::MENSUAL, null);
        if ($partner === null) { $this->skip($label, 'sin mensual libre en nodo semanal'); return; }
        $share = $this->shareRepo->findActiveForPartner($partner);
        if ($share === null) { $this->skip($label, 'socio sin cesta activa'); return; }
        $month = $this->nextMonth();
        if ($month === null || count($month['baskets']) < 3) { $this->skip($label, 'mes futuro insuficiente'); return; }

        // Su cesta mensual pasa a la 2ª entrega del mes (espejo del edit in situ).
        $share->setDayMonthOrder(2);
        $this->em->flush();
        $this->generator->reconcilePartnerFrom($partner, new \DateTime('today'));

        // Cierre global de la 1ª semana, ANTES de generar el mes.
        $cierre = new DeliveryException();
        $cierre->setBasket($month['baskets'][0]);
        $cierre->setNode(null);
        $cierre->setShiftedDate(null);
        $cierre->setNotes('verif: sticky mensual');
        $this->em->persist($cierre);
        $this->em->flush();

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $got = [];
        foreach ($month['baskets'] as $b) {
            if ($wbRepo->findOneBy(['basket' => $b, 'partner' => $partner]) !== null) {
                $got[] = $b->getId();
            }
        }
        $expected = $month['baskets'][1]->getId();  // la 2ª de CALENDARIO, pese al cierre de la 1ª

        $ok = $got === [$expected];
        $this->record($label, $ok, sprintf(
            'socio %d · cierre en %s · entrega en [%s] (debe [%d]: su 2ª de calendario, no la 3ª)',
            $partner->getId(), $month['baskets'][0]->getDate()->format('Y-m-d'),
            implode(',', $got), $expected,
        ));
    }

    /**
     * Cesta extra puntual — SUBIR la cantidad de quien ya recoge. Sobre un socio
     * semanal de un mes ya generado, AÑADE 2 cestas a su entrega y comprueba: sigue
     * habiendo UNA sola entrega (no se duplica el WeeklyBasket), la verdura sube
     * exactamente en 2 (sea cual sea su cantidad de partida), el amount queda coherente,
     * el listado lo refleja, y —clave— un regenerado de esa semana NO pisa el cambio
     * (la rama reuse solo lee).
     */
    private function extraBasketSumScenario(): void
    {
        $label = 'Cesta extra (subir cantidad)';
        $partner = $this->pickPartner(self::SEMANAL, false) ?? $this->pickPartner(self::SEMANAL, true);
        if ($partner === null) { $this->skip($label, 'sin semanal libre sin huevos'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip($label, 'sin mes futuro sin generar'); return; }

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }
        $target = $month['baskets'][0];

        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $before = (float) ($this->wbVegetable($wbRepo->findOneBy(['partner' => $partner, 'basket' => $target])) ?? '0');
        $expected = number_format($before + 2, 2, '.', '');

        $this->extraBasketEditor->addToDelivery(
            $partner, $target, [BasketComponent::ID_VEGETABLES => '2'], 'verif: +2 cestas', 'verif',
        );

        $wbCount = $wbRepo->count(['partner' => $partner, 'basket' => $target]);
        $wb = $wbRepo->findOneBy(['partner' => $partner, 'basket' => $target]);
        $afterSet = $wb !== null ? $this->wbVegetable($wb) : null;
        $amount = $wb?->getAmount() ?? -1;

        // Regenerar NO debe pisar el cambio (la semana ya generada entra por reuse).
        $this->generator->generateForBasket($target);
        $wb = $wbRepo->findOneBy(['partner' => $partner, 'basket' => $target]);
        $afterRegen = $wb !== null ? $this->wbVegetable($wb) : null;

        $node = $partner->getWeeklyBasketGroup()?->getNode();
        $sheetCestas = $node !== null ? $this->sheetPartnerCestas($node, $target, $partner) : null;

        $ok = $wbCount === 1
            && $afterSet === $expected
            && $amount === (int) round($before + 2)
            && $afterRegen === $expected
            && ($node === null || $sheetCestas === ($before + 2));
        $this->record($label, $ok, sprintf(
            'socio %d · basket %d · 1 WB=%s · verdura %s→%s (esperado %s) · amount=%d · tras regenerar=%s · listado=%s',
            $partner->getId(), $target->getId(), $wbCount === 1 ? 'sí' : 'NO',
            number_format($before, 2, '.', ''), $afterSet ?? 'null', $expected, $amount, $afterRegen ?? 'null',
            $sheetCestas === null ? 'n/a' : (string) $sheetCestas,
        ));
    }

    /**
     * Cesta extra puntual — DAR cesta a quien NO le tocaba. Sobre un socio quincenal,
     * en un viernes del mes donde su cohorte no reparte (pero el nodo semanal sí), le
     * crea una entrega de 1 cesta y comprueba: antes no tenía entrega, después tiene
     * UNA, con su verdura, sale en el listado, y un regenerado no la borra.
     */
    private function extraBasketNewScenario(): void
    {
        $label = 'Cesta extra (no le tocaba)';
        $partner = $this->pickPartner(self::QUINCENAL, false);
        if ($partner === null) { $this->skip($label, 'sin quincenal libre'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip($label, 'sin mes futuro sin generar'); return; }

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        // Un viernes del mes donde el socio NO reparte (cohorte contraria) pero el nodo SÍ.
        $deliveryDays = $this->expectedDeliveryDates(self::QUINCENAL, $month, $this->cohortOfPartner($partner));
        $freeDays = array_values(array_diff(
            array_map(static fn (Basket $b) => $b->getId(), $month['baskets']),
            $deliveryDays,
        ));
        if ($freeDays === []) { $this->skip($label, 'sin viernes libre del socio'); return; }
        $target = $this->basketById($freeDays[0]);

        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        $before = $wbRepo->count(['partner' => $partner, 'basket' => $target]);

        $this->extraBasketEditor->addToDelivery(
            $partner, $target, [BasketComponent::ID_VEGETABLES => '1'], 'verif: cesta a quien no le tocaba', 'verif',
        );

        $wbCount = $wbRepo->count(['partner' => $partner, 'basket' => $target]);
        $wb = $wbRepo->findOneBy(['partner' => $partner, 'basket' => $target]);
        $verdura = $wb !== null ? $this->wbVegetable($wb) : null;

        $node = $partner->getWeeklyBasketGroup()?->getNode();
        $inSheet = $node !== null && $this->sheetContainsPartner($node, $target, $partner);

        // Regenerar NO debe borrarla.
        $this->generator->generateForBasket($target);
        $after = $wbRepo->count(['partner' => $partner, 'basket' => $target]);

        $ok = $before === 0 && $wbCount === 1 && $verdura === '1.00'
            && ($node === null || $inSheet) && $after === 1;
        $this->record($label, $ok, sprintf(
            'socio %d · basket %d · antes=%d (0) · tras fijar=%d (1) · verdura=%s · en listado=%s · tras regenerar=%d (1)',
            $partner->getId(), $target->getId(), $before, $wbCount, $verdura ?? 'null',
            $node === null ? 'n/a' : ($inSheet ? 'sí' : 'NO'), $after,
        ));
    }

    /**
     * Traslado puntual de LUGAR — un socio de un nodo biweekly (Madrid/Cascorro) recoge
     * en su semana activa la cesta en Torremocha (semanal). Comprueba: aparece en el
     * listado de Torremocha, ya NO en el de su nodo, su delivery_date pasa al día físico
     * del destino (viernes, no miércoles), y un regenerado no lo revierte.
     */
    private function relocateNodeScenario(): void
    {
        $label = 'Recoger en otro nodo';
        $partner = $this->pickPartner(self::QUINCENAL, null, Node::CADENCE_BIWEEKLY);
        if ($partner === null) { $this->skip($label, 'sin quincenal en nodo biweekly'); return; }
        $originNode = $partner->getWeeklyBasketGroup()?->getNode();
        if ($originNode === null) { $this->skip($label, 'el socio elegido no tiene nodo'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip($label, 'sin mes futuro sin generar'); return; }

        $destNode = $this->nodeRepo->findOneBy(['cadence' => Node::CADENCE_WEEKLY]);
        if ($destNode === null) { $this->skip($label, 'sin nodo semanal destino'); return; }
        $destGroup = $this->em->getRepository(WeeklyBasketGroup::class)->findOneBy(['node' => $destNode]);
        if ($destGroup === null) { $this->skip($label, 'el nodo destino no tiene grupos'); return; }

        // Una semana del mes donde reparten AMBOS (el origen para que el socio tenga cesta).
        $target = null;
        foreach ($month['baskets'] as $b) {
            if ($this->nodeDeliveryDate->deliversInBasket($b, $originNode) && $this->nodeDeliveryDate->deliversInBasket($b, $destNode)) {
                $target = $b;
                break;
            }
        }
        if ($target === null) { $this->skip($label, 'sin semana común origen/destino'); return; }

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $this->relocator->relocate($partner, $target, $destGroup, 'verif');

        $inDest = $this->sheetContainsPartner($destNode, $target, $partner);
        $inOrigin = $this->sheetContainsPartner($originNode, $target, $partner);
        $wb = $this->em->getRepository(WeeklyBasket::class)->findOneBy(['partner' => $partner, 'basket' => $target]);
        $expectedDate = $this->nodeDeliveryDate->physicalDateFor($target, $destNode);
        $dateOk = $wb !== null && $expectedDate !== null
            && $wb->getDeliveryDate()?->format('Y-m-d') === $expectedDate->format('Y-m-d');

        // El CALENDARIO del socio debe colocar la entrega en la fecha del nodo destino, igual
        // que el listado (hueco listado↔calendario que cazó el bug de Franco).
        $calDate = $this->calendarSlotDateFor($partner, $target);
        $calOk = $calDate !== null && $expectedDate !== null
            && $calDate->format('Y-m-d') === $expectedDate->format('Y-m-d');

        // Regenerar NO debe revertir el traslado (la semana ya generada entra por reuse).
        $this->generator->generateForBasket($target);
        $stillDest = $this->sheetContainsPartner($destNode, $target, $partner);

        $ok = $inDest && !$inOrigin && $dateOk && $calOk && $stillDest;
        $this->record($label, $ok, sprintf(
            'socio %d · basket %d · en %s=%s · fuera de %s=%s · fecha destino=%s · calendario=%s · tras regenerar en destino=%s',
            $partner->getId(), $target->getId(),
            $destNode->getName(), $inDest ? 'sí' : 'NO',
            $originNode->getName(), $inOrigin ? 'NO (sigue)' : 'sí',
            $dateOk ? 'ok' : 'MAL', $calOk ? 'ok' : 'MAL', $stillDest ? 'sí' : 'NO',
        ));
    }

    /**
     * Cambio de nodo modelado como OVERRIDE (PartnerNodeOverride) sobre una semana DIBUJADA
     * (futura, sin generar). A diferencia de relocateNodeScenario (que genera y mueve la
     * piedra), aquí NO se genera: se inserta el override y se comprueba la PROYECCIÓN. El
     * socio (de un nodo biweekly) debe salir del dibujo de su nodo de casa y aparecer en el
     * dibujo del nodo destino, en una semana donde ambos reparten. Es la red del paso 2 del
     * modelo de overrides (el motor; el relocate que crea el override viene después).
     */
    private function nodeOverrideDrawnScenario(): void
    {
        $label = 'Cambio de nodo (override) en semana dibujada';
        $partner = $this->pickPartner(self::QUINCENAL, null, Node::CADENCE_BIWEEKLY);
        if ($partner === null) { $this->skip($label, 'sin quincenal en nodo biweekly'); return; }
        $originNode = $partner->getWeeklyBasketGroup()?->getNode();
        if ($originNode === null) { $this->skip($label, 'el socio elegido no tiene nodo'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip($label, 'sin mes futuro sin generar'); return; }

        $destNode = $this->nodeRepo->findOneBy(['cadence' => Node::CADENCE_WEEKLY]);
        if ($destNode === null) { $this->skip($label, 'sin nodo semanal destino'); return; }
        $destGroup = $this->em->getRepository(WeeklyBasketGroup::class)->findOneBy(['node' => $destNode]);
        if ($destGroup === null) { $this->skip($label, 'el nodo destino no tiene grupos'); return; }

        // Semana del mes donde reparten AMBOS (el origen para que el socio tenga cesta).
        $target = null;
        foreach ($month['baskets'] as $b) {
            if ($this->nodeDeliveryDate->deliversInBasket($b, $originNode) && $this->nodeDeliveryDate->deliversInBasket($b, $destNode)) {
                $target = $b;
                break;
            }
        }
        if ($target === null) { $this->skip($label, 'sin semana común origen/destino'); return; }

        // Override directo (sin pasar por el relocate, que aún mueve piedra). NO se genera:
        // la semana queda dibujada.
        $override = new PartnerNodeOverride($partner, $target, $destGroup);
        $this->em->persist($override);
        $this->em->flush();

        // 1) DIBUJO (proyección, semana sin generar): el override mueve dónde recoge.
        $destDrawn = $this->sheet->shape($this->generator->projectLinesForNode($destNode, $target));
        $originDrawn = $this->sheet->shape($this->generator->projectLinesForNode($originNode, $target));
        $inDest = $this->sheetDataContainsPartner($destDrawn, $partner);
        $inOrigin = $this->sheetDataContainsPartner($originDrawn, $partner);

        // 1b) CALENDARIO del socio sobre la semana DIBUJADA: debe colocar la entrega en la fecha
        // física del nodo DESTINO, no en la de casa. Es el hueco exacto que tenía el bug de
        // Franco (el proyector del calendario no aplicaba el override en semanas sin generar).
        $calDateDrawn = $this->calendarSlotDateFor($partner, $target);
        $expectedDestDate = $this->nodeDeliveryDate->physicalDateFor($target, $destNode);
        $calDrawnOk = $calDateDrawn !== null && $expectedDestDate !== null
            && $calDateDrawn->format('Y-m-d') === $expectedDestDate->format('Y-m-d');

        // 2) CONGELAR la semana: la materialización debe respetar el override igual que el
        // dibujo (piedra == dibujo). Es la red del paso 4 (createWeeklyBasketsFromShares).
        $this->generator->generateForBasket($target);
        $inDestStone = $this->sheetDataContainsPartner($this->sheet->build($destNode, $target), $partner);
        $inOriginStone = $this->sheetDataContainsPartner($this->sheet->build($originNode, $target), $partner);

        $ok = $inDest && !$inOrigin && $calDrawnOk && $inDestStone && !$inOriginStone;
        $this->record($label, $ok, sprintf(
            'socio %d · semana %d · dibujo: en %s=%s/fuera %s=%s · calendario dibujo=%s · piedra (tras congelar): en %s=%s/fuera %s=%s',
            $partner->getId(), $target->getId(),
            $destNode->getName(), $inDest ? 'sí' : 'NO', $originNode->getName(), $inOrigin ? 'NO' : 'sí',
            $calDrawnOk ? 'ok' : 'MAL',
            $destNode->getName(), $inDestStone ? 'sí' : 'NO', $originNode->getName(), $inOriginStone ? 'NO' : 'sí',
        ));
    }

    /**
     * Cesta extra como OVERRIDE sobre una semana DIBUJADA (futura, sin generar), a un socio
     * SUSCRIPTOR: la proyección debe sumar el delta al dibujar, y al congelar la piedra debe
     * salir IGUAL (proyección == materializado). Es la red de la capacidad nueva del override
     * (planificar a futuro sin piedra parcial), análoga a nodeOverrideDrawnScenario.
     */
    private function extraDrawnWeekScenario(): void
    {
        $label = 'Cesta extra en semana dibujada (proyección == piedra)';
        $partner = $this->pickPartner(self::SEMANAL, false) ?? $this->pickPartner(self::SEMANAL, true);
        if ($partner === null) { $this->skip($label, 'sin semanal libre sin huevos'); return; }
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        if ($node === null) { $this->skip($label, 'socio sin nodo'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip($label, 'sin mes futuro sin generar'); return; }
        $target = $month['baskets'][0];

        // Base de patrón dibujada ANTES de la extra (semanal = 1 cesta).
        $base = $this->sheetDataPartnerCestas($this->sheet->shape($this->generator->projectLinesForNode($node, $target)), $partner);

        // Añadir +2 sobre la semana DIBUJADA (no se genera): solo override.
        $this->extraBasketEditor->addToDelivery($partner, $target, [BasketComponent::ID_VEGETABLES => '2'], 'verif drawn', 'verif');

        // 1) DIBUJO: la proyección suma el delta.
        $drawn = $this->sheetDataPartnerCestas($this->sheet->shape($this->generator->projectLinesForNode($node, $target)), $partner);

        // 2) CONGELAR: la materialización aplica el override (piedra == dibujo).
        $this->generator->generateForBasket($target);
        $stone = $this->sheetPartnerCestas($node, $target, $partner);

        $expected = ($base ?? 0.0) + 2;
        $ok = $drawn === $expected && $stone === $expected;
        $this->record($label, $ok, sprintf(
            'socio %d · semana %d · base=%s · dibujo=%s · piedra=%s (esperado %s)',
            $partner->getId(), $target->getId(),
            $base === null ? 'null' : (string) $base,
            $drawn === null ? 'null' : (string) $drawn,
            $stone === null ? 'null' : (string) $stone,
            (string) $expected,
        ));
    }

    /**
     * Cesta extra a un NO-SUSCRIPTOR (socio activo sin PartnerBasketShare): al no tener
     * modalidad, debe salir en la sección "Cestas extra" del listado, tanto en el DIBUJO como
     * tras CONGELAR. Blinda el camino de no-suscriptor (caso AMUMI).
     */
    private function extraNonSubscriberScenario(): void
    {
        $label = 'Cesta extra a no-suscriptor (sección Cestas extra)';
        $partner = $this->pickNonSubscriber();
        if ($partner === null) { $this->skip($label, 'sin socio activo sin suscripción en nodo semanal'); return; }
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        if ($node === null) { $this->skip($label, 'socio sin nodo'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip($label, 'sin mes futuro sin generar'); return; }
        $target = $month['baskets'][0];

        $this->extraBasketEditor->addToDelivery($partner, $target, [BasketComponent::ID_VEGETABLES => '1', BasketComponent::ID_EGGS => '1'], 'verif no-sub', 'verif');

        // DIBUJO: sale en la sección "Cestas extra".
        $drawnGroup = $this->sheetGroupOfPartner($this->sheet->shape($this->generator->projectLinesForNode($node, $target)), $partner);

        // CONGELAR: idem en piedra.
        $this->generator->generateForBasket($target);
        $stoneGroup = $this->sheetGroupOfPartner($this->sheet->build($node, $target), $partner);

        $ok = $drawnGroup === 'Cestas extra' && $stoneGroup === 'Cestas extra';
        $this->record($label, $ok, sprintf(
            'socio %d · semana %d · sección dibujo=%s · sección piedra=%s (esperado "Cestas extra")',
            $partner->getId(), $target->getId(), $drawnGroup ?? 'AUSENTE', $stoneGroup ?? 'AUSENTE',
        ));
    }

    /**
     * Ciclo completo de la cesta extra: DIBUJAR (proyección la suma) → CONGELAR (entra en
     * piedra) → DESHACER (removeExtra borra el override y revierte la piedra al patrón). Blinda
     * que "no recoge"/quitar una extra vuelve exactamente a la cesta base.
     */
    private function removeExtraScenario(): void
    {
        $label = 'Cesta extra: dibujar → congelar → deshacer';
        $partner = $this->pickPartner(self::SEMANAL, false) ?? $this->pickPartner(self::SEMANAL, true);
        if ($partner === null) { $this->skip($label, 'sin semanal libre sin huevos'); return; }
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        if ($node === null) { $this->skip($label, 'socio sin nodo'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip($label, 'sin mes futuro sin generar'); return; }
        $target = $month['baskets'][0];

        $base = $this->sheetDataPartnerCestas($this->sheet->shape($this->generator->projectLinesForNode($node, $target)), $partner);

        $this->extraBasketEditor->addToDelivery($partner, $target, [BasketComponent::ID_VEGETABLES => '2'], 'verif', 'verif');
        $drawnWithExtra = $this->sheetDataPartnerCestas($this->sheet->shape($this->generator->projectLinesForNode($node, $target)), $partner);

        $this->generator->generateForBasket($target);
        $stoneWithExtra = $this->sheetPartnerCestas($node, $target, $partner);

        $removed = $this->extraBasketEditor->removeExtra($partner, $target, 'verif');
        $stoneAfterRemove = $this->sheetPartnerCestas($node, $target, $partner);

        $expected = ($base ?? 0.0) + 2;
        $ok = $drawnWithExtra === $expected && $stoneWithExtra === $expected
            && $removed === true && $stoneAfterRemove === $base;
        $this->record($label, $ok, sprintf(
            'socio %d · semana %d · base=%s · con extra dibujo/piedra=%s/%s · quitada=%s · piedra tras quitar=%s (esperado %s)',
            $partner->getId(), $target->getId(),
            $base === null ? 'null' : (string) $base,
            $drawnWithExtra === null ? 'null' : (string) $drawnWithExtra,
            $stoneWithExtra === null ? 'null' : (string) $stoneWithExtra,
            $removed ? 'sí' : 'NO',
            $stoneAfterRemove === null ? 'null' : (string) $stoneAfterRemove,
            $base === null ? 'null' : (string) $base,
        ));
    }

    /**
     * Volver al nodo de casa: tras trasladar a un socio a otro nodo (semana generada), returnHome
     * deshace el traslado — el socio vuelve a salir en su nodo de casa y desaparece del destino.
     * Inversa de relocateNodeScenario.
     */
    private function returnHomeScenario(): void
    {
        $label = 'Volver al nodo (deshacer traslado)';
        $partner = $this->pickPartner(self::QUINCENAL, null, Node::CADENCE_BIWEEKLY);
        if ($partner === null) { $this->skip($label, 'sin quincenal en nodo biweekly'); return; }
        $originNode = $partner->getWeeklyBasketGroup()?->getNode();
        if ($originNode === null) { $this->skip($label, 'el socio elegido no tiene nodo'); return; }
        $month = $this->nextMonth();
        if ($month === null) { $this->skip($label, 'sin mes futuro sin generar'); return; }

        $destNode = $this->nodeRepo->findOneBy(['cadence' => Node::CADENCE_WEEKLY]);
        if ($destNode === null) { $this->skip($label, 'sin nodo semanal destino'); return; }
        $destGroup = $this->em->getRepository(WeeklyBasketGroup::class)->findOneBy(['node' => $destNode]);
        if ($destGroup === null) { $this->skip($label, 'el nodo destino no tiene grupos'); return; }

        $target = null;
        foreach ($month['baskets'] as $b) {
            if ($this->nodeDeliveryDate->deliversInBasket($b, $originNode) && $this->nodeDeliveryDate->deliversInBasket($b, $destNode)) {
                $target = $b;
                break;
            }
        }
        if ($target === null) { $this->skip($label, 'sin semana común origen/destino'); return; }

        foreach ($month['baskets'] as $b) { $this->generator->generateForBasket($b); }

        $this->relocator->relocate($partner, $target, $destGroup, 'verif');
        $inDestAfterRelocate = $this->sheetContainsPartner($destNode, $target, $partner);

        // Deshacer: vuelve a su nodo de casa.
        $this->relocator->returnHome($partner, $target, 'verif');
        $inOrigin = $this->sheetContainsPartner($originNode, $target, $partner);
        $inDest = $this->sheetContainsPartner($destNode, $target, $partner);

        $ok = $inDestAfterRelocate && $inOrigin && !$inDest;
        $this->record($label, $ok, sprintf(
            'socio %d · semana %d · tras trasladar en %s=%s · tras volver: en %s=%s / en %s=%s',
            $partner->getId(), $target->getId(),
            $destNode->getName(), $inDestAfterRelocate ? 'sí' : 'NO',
            $originNode->getName(), $inOrigin ? 'sí' : 'NO',
            $destNode->getName(), $inDest ? 'NO (sigue)' : 'sí',
        ));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Cantidad de verdura (cestas) materializada en una entrega, o null si no lleva
     * verdura. Lee la línea WeeklyBasketItem del componente verdura.
     *
     * @param WeeklyBasket $wb Entrega a inspeccionar.
     * @return string|null Cantidad decimal de la línea de verdura, o null.
     */
    private function wbVegetable(WeeklyBasket $wb): ?string
    {
        foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $wb]) as $item) {
            if ($item->getBasketComponent()?->getId() === BasketComponent::ID_VEGETABLES) {
                return $item->getAmount();
            }
        }
        return null;
    }

    /**
     * Cestas (verdura) con que un socio aparece en el listado de un nodo+semana, o null
     * si no aparece. Para comprobar que la cesta extra llega al plano del listado/PDF.
     *
     * @param Node    $node    Nodo del listado.
     * @param Basket  $basket  Semana.
     * @param Partner $partner Socio buscado.
     * @return float|null Cestas de verdura en su fila del listado, o null si no está.
     */
    private function sheetPartnerCestas(Node $node, Basket $basket, Partner $partner): ?float
    {
        $data = $this->sheet->build($node, $basket);
        $name = $partner->getNameForDelivery();
        foreach ($data['groups'] as $g) {
            foreach ($g['modalities'] as $m) {
                foreach ($m['rows'] as $r) {
                    if (($r['name'] ?? '') === $name) {
                        return (float) $r['cestas'];
                    }
                }
            }
        }
        foreach ($data['shared']['rows'] as $r) {
            if (($r['name'] ?? '') === $name) {
                return (float) $r['cestas'];
            }
        }
        return null;
    }

    /**
     * Equivalencia proyección↔materializado: para cada viernes de un mes futuro
     * limpio, el listado DIBUJADO (proyección read-only, antes de generar) debe
     * salir idéntico al MATERIALIZADO (tras generar). Es la red que blinda la
     * fidelidad de {@see WeeklyBasketGenerator::projectLinesForNode}: si divergen,
     * la proyección no es fiel y el rework de materialización tardía mostraría una
     * semana futura distinta de la que acabará repartiéndose.
     *
     * Corre sobre el nodo SEMANAL (Torremocha): reparte todos los viernes, así que
     * cubre patrón semanal, quincenal (cohorte), mensual y huevo-extra SANTOS. No
     * inserta shifts: valida la fidelidad del patrón base (los shifts ya los cubren
     * los escenarios de mover/no-recoge contra el plano interno).
     */
    private function projectionEquivalenceScenario(): void
    {
        $label = 'Equivalencia proyección↔materializado';
        $node = $this->nodeRepo->findOneBy(['cadence' => Node::CADENCE_WEEKLY]);
        if ($node === null) {
            $this->skip($label, 'sin nodo semanal');
            return;
        }
        $month = $this->nextMonth();
        if ($month === null) {
            $this->skip($label, 'sin mes futuro sin generar');
            return;
        }

        $mismatch = null;
        foreach ($month['baskets'] as $b) {
            // Dibujar ANTES de generar (la proyección no debe depender de que exista
            // listado), luego materializar y comparar.
            $drawn = $this->sheet->shape($this->generator->projectLinesForNode($node, $b));
            $this->generator->generateForBasket($b);
            $stone = $this->sheet->build($node, $b);

            if ($drawn != $stone) {
                $mismatch = sprintf(
                    'basket %d (%s)%sdibujo:  [%s]%spiedra: [%s]',
                    $b->getId(), $b->getDate()->format('Y-m-d'), \PHP_EOL . '      ',
                    $this->sheetSignature($drawn), \PHP_EOL . '      ',
                    $this->sheetSignature($stone),
                );
                break;
            }
        }

        $this->record($label, $mismatch === null, $mismatch ?? sprintf(
            'nodo %s · mes %s · %d viernes: dibujo === piedra',
            $node->getName(), $month['first']->getDate()->format('Y-m'), count($month['baskets']),
        ));
    }

    /**
     * Equivalencia proyección↔materializado CON un intent POR COMPONENTE (mover solo
     * la verdura). Un socio mensual mueve su verdura de su viernes de cesta a otro
     * viernes del mes: el dibujo debe salir idéntico al materializado en TODO el mes
     * —el origen pierde la verdura, el destino la gana—. Blinda
     * {@see WeeklyBasketGenerator::projectDeliveriesForNode} aplicando el
     * mover-componente (antes la proyección lo ignoraba: vistas-reparto-dibujar punto 3).
     *
     * Cubre crear-entrega-destino y quitar-componente-origen. El realineado de
     * verdura-sobre-solo-huevo (caso SANTOS, cuando la verdura cae en una entrega de
     * solo huevo ya existente) NO lo ejercita este escenario; queda como ampliación.
     */
    private function projectionEquivalenceComponentMoveScenario(): void
    {
        $label = 'Equivalencia proyección↔materializado (mover componente)';
        $partner = $this->pickPartner(self::MENSUAL, false);
        if ($partner === null) {
            $this->skip($label, 'sin mensual libre sin huevos');
            return;
        }
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        if ($node === null) {
            $this->skip($label, 'socio sin nodo');
            return;
        }
        $month = $this->nextMonth();
        if ($month === null || count($month['baskets']) < 3) {
            $this->skip($label, 'mes futuro insuficiente');
            return;
        }

        // Día de cesta del mensual (2º viernes) → mover la verdura a un viernes libre.
        $deliveryDays = $this->expectedDeliveryDates(self::MENSUAL, $month, $this->cohortOfPartner($partner));
        $freeDays = array_values(array_diff(
            array_map(static fn (Basket $b) => $b->getId(), $month['baskets']),
            $deliveryDays,
        ));
        if ($deliveryDays === [] || $freeDays === []) {
            $this->skip($label, 'sin par origen/destino');
            return;
        }

        $verdura = $this->em->find(BasketComponent::class, BasketComponent::ID_VEGETABLES);
        $this->insertComponentShift(
            $partner,
            $this->basketById($deliveryDays[0]),
            $this->basketById($freeDays[0]),
            $verdura,
        );

        $mismatch = null;
        foreach ($month['baskets'] as $b) {
            // Dibujar ANTES de generar, luego materializar y comparar (igual que la
            // equivalencia base, pero con el intent por componente ya insertado).
            $drawn = $this->sheet->shape($this->generator->projectLinesForNode($node, $b));
            $this->generator->generateForBasket($b);
            $stone = $this->sheet->build($node, $b);

            if ($drawn != $stone) {
                $mismatch = sprintf(
                    'basket %d (%s)%sdibujo:  [%s]%spiedra: [%s]',
                    $b->getId(), $b->getDate()->format('Y-m-d'), \PHP_EOL . '      ',
                    $this->sheetSignature($drawn), \PHP_EOL . '      ',
                    $this->sheetSignature($stone),
                );
                break;
            }
        }

        $this->record($label, $mismatch === null, $mismatch ?? sprintf(
            'socio %d · verdura %d→%d · %d viernes: dibujo === piedra',
            $partner->getId(), $deliveryDays[0], $freeDays[0], count($month['baskets']),
        ));
    }

    /**
     * Firma compacta y ordenada de las filas de un listado (grupos + compartidas)
     * para pinpointear una divergencia: "nombre:código:cestas:Nh" por fila.
     *
     * @param array<string,mixed> $sheet
     */
    private function sheetSignature(array $sheet): string
    {
        $rows = [];
        foreach ($sheet['groups'] as $g) {
            foreach ($g['modalities'] as $m) {
                foreach ($m['rows'] as $r) {
                    $rows[] = sprintf('%s:%s:%s:%dh', $r['name'], $r['code'], $r['cestas'], $r['egg_count']);
                }
            }
        }
        foreach ($sheet['shared']['rows'] as $r) {
            $rows[] = sprintf('%s:%s:%s:%dh*', $r['name'], $r['code'], $r['cestas'], $r['egg_count']);
        }
        sort($rows);

        return implode(' | ', $rows);
    }

    private function buildTargetShare(PartnerBasketShare $old, int $toShare, array $month): PartnerBasketShare
    {
        $shareEntity = $this->em->getRepository(BasketShare::class)->find($toShare);
        $new = new PartnerBasketShare();
        $new->setPartner($old->getPartner());
        $new->setBasketShare($shareEntity);
        $new->setAmount($old->getAmount());
        $new->setEggAmount($old->getEggAmount());
        $new->setEggPeriod($old->getEggPeriod());
        $new->setDayMonthOrder(in_array($toShare, [self::MENSUAL], true) ? 2 : null);
        $new->setEggDayMonthOrder($old->getEggDayMonthOrder());
        // →Quincenal exige cohorte: el comando/form la pide; aquí la fijamos a la que
        // toca el 1er viernes del mes para que reparta de forma comprobable.
        $cohort = in_array($toShare, [self::QUINCENAL, self::COMP_QUINCENAL], true)
            ? $this->cohortResolver->cohortForBasket($month['first'])
            : null;
        $new->setDeliveryGroup($cohort);
        $new->setMonthPrice((string) ($shareEntity->getMonthPrice() * $new->getAmount()));
        $new->setEggMonthPrice((string) ($old->getEggAmount() ? $old->getEggAmount()->getMonthPrice() * $new->getAmount() : 0));

        return $new;
    }

    /** Viernes (basket ids) en los que la modalidad destino repartiría dentro del mes. */
    private function expectedDeliveryDates(int $share, array $month, ?string $cohort): array
    {
        $ids = [];
        foreach ($month['baskets'] as $i => $b) {
            $delivers = match (true) {
                in_array($share, [self::SEMANAL, self::COMP_SEMANAL], true) => true,
                in_array($share, [self::QUINCENAL, self::COMP_QUINCENAL], true) => $this->cohortResolver->cohortForBasket($b) === $cohort,
                $share === self::MENSUAL => ($i + 1) === 2, // day_month_order=2 (2º viernes)
                default => false,
            };
            if ($delivers) {
                $ids[] = $b->getId();
            }
        }
        return $ids;
    }

    private function listadoMatches(Partner $partner, array $month, array $expectedIds, int $share, ?string $cohort): bool
    {
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        if ($node === null) {
            return true; // sin nodo no se puede comprobar el sheet; el plano interno ya cubre
        }
        // Solo-positivo: el socio DEBE salir en NodeDeliverySheet en cada viernes esperado.
        // No comprobamos "ausente en los demás" porque getNameForDelivery NO es único
        // (hay tocayas) y daría falsos negativos; la ausencia ya está garantizada por la
        // aserción de WeeklyBasket (el sheet es un shaper puro de esos WB).
        foreach ($expectedIds as $bid) {
            if (!$this->sheetContainsPartner($node, $this->basketById($bid), $partner)) {
                return false;
            }
        }
        return true;
    }

    private function sheetContainsPartner(Node $node, Basket $basket, Partner $partner): bool
    {
        return $this->sheetDataContainsPartner($this->sheet->build($node, $basket), $partner);
    }

    /**
     * ¿Aparece el socio en un sheet YA construido (de piedra o dibujado)? Igual que
     * {@see sheetContainsPartner} pero sobre el array, para poder comprobar el listado
     * DIBUJADO (shape de la proyección), no solo el de piedra (build).
     *
     * @param array<string,mixed> $data    Sheet de NodeDeliverySheet (build o shape).
     * @param Partner             $partner Socio buscado.
     */
    private function sheetDataContainsPartner(array $data, Partner $partner): bool
    {
        $name = $partner->getNameForDelivery();
        foreach ($data['groups'] as $g) {
            foreach ($g['modalities'] as $m) {
                foreach ($m['rows'] as $r) {
                    if (($r['name'] ?? '') === $name) {
                        return true;
                    }
                }
            }
        }
        foreach ($data['shared']['rows'] as $r) {
            if (($r['name'] ?? '') === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cestas (verdura) con que un socio aparece en un sheet YA construido (build o shape), o
     * null si no aparece. Como {@see sheetPartnerCestas} pero sobre el array, para poder leer
     * el listado DIBUJADO (proyección).
     *
     * @param array<string,mixed> $data
     */
    private function sheetDataPartnerCestas(array $data, Partner $partner): ?float
    {
        $name = $partner->getNameForDelivery();
        foreach ($data['groups'] as $g) {
            foreach ($g['modalities'] as $m) {
                foreach ($m['rows'] as $r) {
                    if (($r['name'] ?? '') === $name) {
                        return (float) $r['cestas'];
                    }
                }
            }
        }
        foreach ($data['shared']['rows'] as $r) {
            if (($r['name'] ?? '') === $name) {
                return (float) $r['cestas'];
            }
        }
        return null;
    }

    /**
     * Nombre del GRUPO/sección del sheet en el que aparece el socio (p. ej. "Cestas extra",
     * "Trasladados", o un grupo de recogida), o null si no aparece. Para comprobar que una
     * entrega va a la sección correcta.
     *
     * @param array<string,mixed> $data
     */
    private function sheetGroupOfPartner(array $data, Partner $partner): ?string
    {
        $name = $partner->getNameForDelivery();
        foreach ($data['groups'] as $g) {
            foreach ($g['modalities'] as $m) {
                foreach ($m['rows'] as $r) {
                    if (($r['name'] ?? '') === $name) {
                        return $g['name'];
                    }
                }
            }
        }
        foreach ($data['shared']['rows'] as $r) {
            if (($r['name'] ?? '') === $name) {
                return 'compartidas';
            }
        }
        return null;
    }

    /**
     * Elige un socio activo SIN suscripción (sin PartnerBasketShare activo) en un nodo semanal
     * (reparte todas las semanas, así el target dibujado le toca), que no se haya usado ya. Para
     * el escenario de cesta extra a no-suscriptor.
     */
    private function pickNonSubscriber(): ?Partner
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Partner::class, 'p')
            ->join('p.weekly_basket_group', 'wbg')
            ->join('wbg.node', 'n')
            ->where('p.status = :activo')
            ->andWhere('p.parent IS NULL')
            ->andWhere('n.cadence = :weekly')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . PartnerBasketShare::class . ' s WHERE s.partner = p AND s.is_active = 1 AND s.end_date IS NULL)')
            ->setParameter('activo', 'ACTIVO')
            ->setParameter('weekly', Node::CADENCE_WEEKLY)
            ->orderBy('p.id', 'ASC');

        foreach ($qb->getQuery()->getResult() as $partner) {
            if (!in_array($partner->getId(), $this->usedPartners, true)) {
                $this->usedPartners[] = $partner->getId();
                return $partner;
            }
        }
        return null;
    }

    /** @return int[] basket ids del mes donde el socio tiene WeeklyBasket, en orden. */
    private function partnerDeliveryBasketIds(Partner $partner, array $baskets): array
    {
        return array_keys($this->wbRows($partner, $baskets));
    }

    /** @return array<int, array{bs:int, verdura:?string}> basketId → resumen WB. */
    private function wbRows(Partner $partner, array $baskets): array
    {
        $ids = array_map(static fn (Basket $b) => $b->getId(), $baskets);
        $rows = [];
        $wbs = $this->em->getRepository(WeeklyBasket::class)->findBy(['partner' => $partner]);
        foreach ($wbs as $wb) {
            $bid = $wb->getBasket()?->getId();
            if (!in_array($bid, $ids, true)) {
                continue;
            }
            $verdura = null;
            foreach ($this->em->getRepository(WeeklyBasketItem::class)->findBy(['weeklyBasket' => $wb]) as $item) {
                if ($item->getBasketComponent()?->getId() === 1) {
                    $verdura = $item->getAmount();
                }
            }
            $rows[$bid] = ['bs' => (int) $wb->getBasketShare()?->getId(), 'verdura' => $verdura];
        }
        ksort($rows);
        // reordenar por la posición del basket en el mes
        $ordered = [];
        foreach ($baskets as $b) {
            if (isset($rows[$b->getId()])) {
                $ordered[$b->getId()] = $rows[$b->getId()];
            }
        }
        return $ordered;
    }

    private function cohortOfPartner(Partner $partner): ?string
    {
        $share = $this->shareRepo->findActiveForPartner($partner);
        return $share?->getDeliveryGroup();
    }

    private function insertShift(Partner $partner, Basket $from, ?Basket $to): void
    {
        $shift = new PartnerDeliveryShift($partner, $from, $to);
        $this->em->persist($shift);
        $this->em->flush();
    }

    private function insertComponentShift(Partner $partner, Basket $from, Basket $to, BasketComponent $component): void
    {
        $shift = new PartnerDeliveryShift($partner, $from, $to, $component);
        $this->em->persist($shift);
        $this->em->flush();
    }

    /**
     * Elige un socio activo con la modalidad pedida, en un nodo de la cadencia indicada
     * (weekly = Torremocha por defecto; biweekly = Madrid) y (si se indica) con/sin
     * huevos, que no se haya usado ya. $hasEggs null = indiferente.
     */
    private function pickPartner(int $share, ?bool $hasEggs, string $nodeCadence = Node::CADENCE_WEEKLY): ?Partner
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Partner::class, 'p')
            ->join('p.weekly_basket_group', 'wbg')
            ->join('wbg.node', 'n')
            ->join(PartnerBasketShare::class, 'pbs', 'WITH', 'pbs.partner = p AND pbs.is_active = 1 AND pbs.end_date IS NULL AND pbs.basket_share = :share')
            ->where('p.status = :activo')
            ->andWhere('p.parent IS NULL')
            ->andWhere('n.cadence = :cadence')
            ->setParameter('share', $share)
            ->setParameter('activo', 'ACTIVO')
            ->setParameter('cadence', $nodeCadence)
            ->orderBy('p.id', 'ASC');
        if ($hasEggs === true) {
            $qb->andWhere('pbs.egg_amount IS NOT NULL');
        } elseif ($hasEggs === false) {
            $qb->andWhere('pbs.egg_amount IS NULL');
        }

        foreach ($qb->getQuery()->getResult() as $partner) {
            if (!in_array($partner->getId(), $this->usedPartners, true)) {
                $this->usedPartners[] = $partner->getId();
                return $partner;
            }
        }
        return null;
    }

    /**
     * Siguiente mes calendario FUTURO completamente sin generar (todos sus viernes a 0 WB).
     * @return array{first:Basket, baskets:Basket[]}|null
     */
    private function nextMonth(): ?array
    {
        $today = new \DateTime('today');
        $candidates = $this->em->createQuery(
            'SELECT b FROM ' . Basket::class . ' b WHERE b.date > :from ORDER BY b.date ASC'
        )->setParameter('from', $today->format('Y-m-d'))->setMaxResults(400)->getResult();

        // Agrupar por año-mes.
        $byMonth = [];
        foreach ($candidates as $b) {
            $byMonth[$b->getDate()->format('Y-m')][] = $b;
        }
        $months = array_values($byMonth);
        // Saltar los meses ya repartidos a escenarios anteriores + buffer de 1 (la ventana del cron).
        for ($k = $this->monthCursor + 1; $k < count($months); $k++) {
            $baskets = $months[$k];
            $generated = false;
            foreach ($baskets as $b) {
                if ($this->em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $b]) !== null) {
                    $generated = true;
                    break;
                }
            }
            if (!$generated) {
                $this->monthCursor = $k;
                return ['first' => $baskets[0], 'baskets' => $baskets];
            }
        }
        return null;
    }

    private function basketById(int $id): Basket
    {
        return $this->em->getRepository(Basket::class)->find($id);
    }

    private function record(string $name, bool $ok, string $detail): void
    {
        $this->results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        $this->io->writeln(sprintf('  %s <comment>%s</comment> — %s', $ok ? '✔' : '✘', $name, $detail));
    }

    private function skip(string $name, string $why): void
    {
        $this->results[] = ['name' => $name, 'ok' => false, 'detail' => 'SKIP: ' . $why];
        $this->io->writeln(sprintf('  <fg=yellow>•</> %s — SKIP: %s', $name, $why));
    }

    private function report(): void
    {
        $fail = array_values(array_filter($this->results, fn (array $r) => !$r['ok']));
        if ($fail === []) {
            $this->io->success(sprintf('%d/%d escenarios OK.', count($this->results), count($this->results)));
            return;
        }
        $this->io->warning(sprintf('%d/%d con problema:', count($fail), count($this->results)));
        foreach ($fail as $r) {
            $this->io->writeln(sprintf('   - %s: %s', $r['name'], $r['detail']));
        }
    }
}
