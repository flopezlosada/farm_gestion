<?php

namespace App\Command;

use App\Entity\BasketShare;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\PartnerRepository;
use App\Service\Partner\PartnerShareEventRecorder;
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
 * La cascada de WeeklyBaskets se indica explícitamente: --drop-baskets (entregas
 * que dejan de tocar en la nueva modalidad) y --convert-baskets (entregas que se
 * mantienen y se re-etiquetan a la nueva modalidad).
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
        private readonly PartnerShareEventRecorder $eventRecorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'ID del socio.')
            ->addOption('to-share', null, InputOption::VALUE_REQUIRED, 'ID de BasketShare destino (1 sem, 2 quin, 3 mens…).')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Fecha efectiva del cambio (YYYY-MM-DD).')
            ->addOption('day-order', null, InputOption::VALUE_REQUIRED, 'day_month_order de la nueva PBS (N-ésima entrega del mes), o vacío.')
            ->addOption('egg-day-order', null, InputOption::VALUE_REQUIRED, 'egg_day_month_order de la nueva PBS (vacío = huevo va con la cesta).')
            ->addOption('drop-baskets', null, InputOption::VALUE_REQUIRED, 'IDs de basket (csv) cuyas entregas del socio se BORRAN.')
            ->addOption('convert-baskets', null, InputOption::VALUE_REQUIRED, 'IDs de basket (csv) cuyas entregas del socio se re-etiquetan a la nueva modalidad.')
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
        $new->setStartDate($effective);
        $new->setEndDate(null);
        $new->setIsActive(true);
        $new->setEggAmount($old->getEggAmount());
        $new->setEggPeriod($old->getEggPeriod());
        $new->setDeliveryGroup($old->getDeliveryGroup());
        $new->setDayMonthOrder($dayOrder === null || $dayOrder === '' ? null : (int) $dayOrder);
        $new->setEggDayMonthOrder($eggDayOrder === null || $eggDayOrder === '' ? null : (int) $eggDayOrder);

        // Precios derivados (misma fórmula que el form admin).
        $new->setMonthPrice($toShare->getMonthPrice() * $new->getAmount());
        $new->setEggMonthPrice($old->getEggAmount() ? $old->getEggAmount()->getMonthPrice() * $new->getAmount() : 0);

        $old->setEndDate($dayBefore);
        $old->setIsActive(false);

        $dropIds = $this->csv($input->getOption('drop-baskets'));
        $convertIds = $this->csv($input->getOption('convert-baskets'));

        $io->title(sprintf('Cambio de modalidad — socio %d (%s)', $partner->getId(), $partner->getName()));
        $io->definitionList(
            ['Desde' => sprintf('PBS %d bs=%d', $old->getId(), $old->getBasketShare()?->getId())],
            ['Hacia' => sprintf('bs=%d day_order=%s, efectivo %s', $toShare->getId(), $dayOrder ?: '—', $effective->format('Y-m-d'))],
            ['Old end_date' => $dayBefore->format('Y-m-d')],
            ['Drop WB en baskets' => implode(',', $dropIds) ?: '—'],
            ['Convert WB en baskets' => implode(',', $convertIds) ?: '—'],
        );

        if ($dryRun) {
            $io->warning('DRY-RUN: no se ha escrito nada.');
            return Command::SUCCESS;
        }

        // Flush previo: asigna id a la nueva PBS (y persiste el cierre de la
        // antigua) antes de recordChange, que hace snapshot vía getId() (: int
        // estricto, no tolera null en entidad transitoria).
        $this->em->persist($new);
        $this->em->flush();

        $this->eventRecorder->recordChange($old, $new, $effective, 'cli');

        // Cascada de WeeklyBaskets.
        $wbRepo = $this->em->getRepository(WeeklyBasket::class);
        foreach ($dropIds as $bid) {
            foreach ($wbRepo->findBy(['partner' => $partner, 'basket' => $bid]) as $wb) {
                $this->em->remove($wb); // ON DELETE CASCADE limpia sus items
            }
        }
        foreach ($convertIds as $bid) {
            foreach ($wbRepo->findBy(['partner' => $partner, 'basket' => $bid]) as $wb) {
                $wb->setBasketShare($toShare);
            }
        }

        $this->em->flush();
        $io->success('Cambio aplicado: PBS partida, BASKET_CHANGE emitido, WBs cascadeados.');

        return Command::SUCCESS;
    }

    /**
     * @return int[]
     */
    private function csv(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        return array_map('intval', array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }
}
