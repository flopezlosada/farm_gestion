<?php

namespace App\Command;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Repository\NodeRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Service\Delivery\BiweeklyCohortResolver;
use App\Service\Delivery\NodeDeliverySheet;
use App\Service\Delivery\WeeklyBasketGenerator;
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
        private readonly BiweeklyCohortResolver $cohortResolver,
        private readonly PartnerBasketShareRepository $shareRepo,
        private readonly NodeRepository $nodeRepo,
    ) {
        parent::__construct();
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

        // Cesta compartida quincenal (bs=6): media cesta en el bloque compartidas.
        $this->sharedQuincenalScenario();

        // Baja de media pareja: no debe crashear y rompe el vínculo (regresión be0ec62).
        $this->sharedBajaScenario();

        // SANTOS: cesta mensual + huevo semanal → cesta+huevo el día mensual, solo-huevo el resto.
        $this->santosScenario();

        $this->report();

        return array_reduce($this->results, fn (int $c, array $r) => $c + ($r['ok'] ? 0 : 1), 0) === 0
            ? Command::SUCCESS
            : Command::FAILURE;
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

    // ---------------------------------------------------------------- helpers

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
        $data = $this->sheet->build($node, $basket);
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

    /**
     * Elige un socio activo de Torremocha con la modalidad pedida y (si se indica) con/sin
     * huevos, que no se haya usado ya. $hasEggs null = indiferente.
     */
    private function pickPartner(int $share, ?bool $hasEggs): ?Partner
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Partner::class, 'p')
            ->join('p.weekly_basket_group', 'wbg')
            ->join('wbg.node', 'n')
            ->join(PartnerBasketShare::class, 'pbs', 'WITH', 'pbs.partner = p AND pbs.is_active = 1 AND pbs.end_date IS NULL AND pbs.basket_share = :share')
            ->where('p.status = :activo')
            ->andWhere('p.parent IS NULL')
            ->andWhere('n.cadence = :weekly')
            ->setParameter('share', $share)
            ->setParameter('activo', 'ACTIVO')
            ->setParameter('weekly', Node::CADENCE_WEEKLY)
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
