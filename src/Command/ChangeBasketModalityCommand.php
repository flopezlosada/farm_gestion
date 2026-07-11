<?php

namespace App\Command;

use App\Entity\BasketShare;
use App\Entity\PartnerBasketShare;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\PartnerRepository;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Partner\BasketModalityChanger;
use App\Service\Partner\BasketPricing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Aplica un CAMBIO DE MODALIDAD de cesta a un socio con fecha efectiva,
 * haciéndolo BIEN: cierra el PBS vigente (end_date = víspera de la fecha
 * efectiva), abre uno nuevo (start_date = fecha efectiva) con la modalidad
 * destino, y emite el evento BASKET_CHANGE vía PartnerShareEventRecorder. El
 * form admin de PBS NO hace esto (sobrescribe en sitio sin histórico ni evento,
 * deuda pbs-form-debt), de ahí este comando-puente hasta que exista la UI.
 *
 * La cascada de WeeklyBaskets es automática: tras partir el histórico, reconcilia
 * cada listado YA generado con fecha >= efectiva al patrón nuevo vía
 * {@see WeeklyBasketGenerator::reconcilePartnerFrom} (añade donde ahora reparte,
 * quita donde ya no, re-etiqueta la modalidad). Las semanas aún sin generar las
 * siembra la generación normal. Ya no hay que adivinar qué baskets borrar o convertir.
 *
 * NO toca mayo si la fecha efectiva es 1-jun: las entregas previas siguen
 * colgando del PBS antiguo. Idempotencia mínima: refuerza con --dry-run.
 */
#[AsCommand(
    name: 'app:change-basket-modality',
    description: 'Cambio de modalidad de cesta con histórico (split PBS + BASKET_CHANGE).'
)]
class ChangeBasketModalityCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerRepository $partnerRepository,
        private readonly PartnerBasketShareRepository $shareRepository,
        private readonly BasketModalityChanger $modalityChanger,
        private readonly WeeklyBasketGenerator $generator,
        private readonly BasketPricing $basketPricing,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'ID del socio.')
            ->addOption('to-share', null, InputOption::VALUE_REQUIRED, 'ID de BasketShare destino (1 sem, 2 quin, 3 mens…).')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Fecha efectiva del cambio (YYYY-MM-DD).')
            ->addOption('day-order', null, InputOption::VALUE_REQUIRED, 'day_month_order de la nueva PBS (N-ésima entrega del mes; -1 = última semana), o vacío. Para el valor negativo usa la sintaxis con «=»: --day-order=-1.')
            ->addOption('egg-day-order', null, InputOption::VALUE_REQUIRED, 'egg_day_month_order de la nueva PBS (vacío = huevo va con la cesta; -1 = última cesta, con --egg-day-order=-1).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No escribe; informa de lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $partner = $this->partnerRepository->find((int) $input->getOption('partner'));
        $toShare = $this->em->getRepository(BasketShare::class)->find((int) $input->getOption('to-share'));
        if ($partner === null || $toShare === null) {
            $io->error('Socio o BasketShare destino no encontrado.');
            return Command::FAILURE;
        }

        $effective = new \DateTime($input->getOption('from'));
        $dayBefore = (clone $effective)->modify('-1 day');

        // PBS vigente en la víspera (la que se cierra). Usamos el día anterior a
        // la fecha efectiva para no coger ya una PBS futura.
        $old = $this->shareRepository->findActiveForPartner($partner, $dayBefore);
        if ($old === null) {
            $io->error(sprintf('El socio %d no tiene PBS activa antes de %s.', $partner->getId(), $effective->format('Y-m-d')));
            return Command::FAILURE;
        }

        $dayOrder = $input->getOption('day-order');
        $eggDayOrder = $input->getOption('egg-day-order');

        // Nueva PBS: copia de la antigua, cambiando modalidad y fechas.
        // (setPartner/setBasketShare/setStartDate/setEndDate devuelven void, no se encadenan.)
        $new = new PartnerBasketShare();
        $new->setPartner($partner);
        $new->setBasketShare($toShare);
        $new->setAmount($old->getAmount());
        $new->setEggAmount($old->getEggAmount());
        $new->setEggPeriod($old->getEggPeriod());
        $new->setDeliveryGroup($old->getDeliveryGroup());
        $new->setDayMonthOrder($dayOrder === null || $dayOrder === '' ? null : (int) $dayOrder);
        $new->setEggDayMonthOrder($eggDayOrder === null || $eggDayOrder === '' ? null : (int) $eggDayOrder);

        // Precios derivados (misma fórmula centralizada que el form admin).
        $this->basketPricing->applyTo($new);

        $io->title(sprintf('Cambio de modalidad — socio %d (%s)', $partner->getId(), $partner->getName()));
        $io->definitionList(
            ['Desde' => sprintf('PBS %d bs=%d', $old->getId(), $old->getBasketShare()?->getId())],
            ['Hacia' => sprintf('bs=%d day_order=%s, efectivo %s', $toShare->getId(), $dayOrder ?: '—', $effective->format('Y-m-d'))],
            ['Old end_date' => $dayBefore->format('Y-m-d')],
        );

        if ($dryRun) {
            $io->warning('DRY-RUN: no se ha escrito nada.');
            return Command::SUCCESS;
        }

        // Split + cierre del histórico + evento BASKET_CHANGE (incluye el flush
        // previo que asigna id a la nueva PBS antes del snapshot del evento).
        $this->modalityChanger->applyChange($new, $effective, 'cli');

        // Cascada automática: reconcilia cada listado ya generado (fecha >= efectiva)
        // al patrón nuevo. Las semanas sin generar las siembra la generación normal.
        $reconciled = $this->generator->reconcilePartnerFrom($partner, $effective);

        $withDelivery = array_keys(array_filter($reconciled));
        $withoutDelivery = array_keys(array_filter($reconciled, static fn (bool $v) => !$v));
        $io->writeln(sprintf(
            'Listados generados reconciliados: %d (con entrega: [%s]; sin entrega: [%s]).',
            count($reconciled),
            implode(',', $withDelivery) ?: '—',
            implode(',', $withoutDelivery) ?: '—',
        ));
        $io->success('Cambio aplicado: PBS partida, BASKET_CHANGE emitido, listado reconciliado.');

        return Command::SUCCESS;
    }
}
