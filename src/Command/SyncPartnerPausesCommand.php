<?php

namespace App\Command;

use App\Entity\Partner;
use App\Repository\PartnerPauseRepository;
use App\Repository\PartnerRepository;
use App\Service\Partner\PartnerPauser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pone la ficha de cada socix en PAUSADO o ACTIVO según sus pausas de hoy: las
 * que empiezan hoy la pausan y las que terminan la reactivan. Pensado para correr
 * a diario de madrugada.
 *
 * El reparto y las cuotas no dependen de esto (leen las pausas directamente), así
 * que un día sin ejecutarse no saca ni mete a nadie en un listado: sólo deja la
 * ficha y los filtros de estado con un día de retraso. El evento se registra con la
 * fecha real de la pausa, no la de la ejecución.
 */
#[AsCommand(name: 'app:sync-partner-pauses', description: 'Pausa y reactiva las fichas de socixs según las fechas de sus pausas.')]
class SyncPartnerPausesCommand extends AbstractCronCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerRepository $partners,
        private readonly PartnerPauseRepository $pauses,
        private readonly PartnerPauser $pauser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora el gate de la tarea programada (ejecución manual)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista a quién cambiaría sin tocar nada');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = new \DateTimeImmutable('today');
        $pausedIds = $this->pauses->findPausedPartnerIdsOn($today);

        // Sólo pueden cambiar: activxs con pausa hoy y pausadxs sin ella.
        $qb = $this->partners->createQueryBuilder('p')
            ->andWhere('p.status = :paused')->setParameter('paused', Partner::STATUS_PAUSADO);
        if ($pausedIds !== []) {
            $qb->orWhere('p.status = :active AND p.id IN (:ids)')
               ->setParameter('active', Partner::STATUS_ACTIVO)
               ->setParameter('ids', $pausedIds);
        }
        $candidates = $qb->getQuery()->getResult();

        $changed = [];
        foreach ($candidates as $partner) {
            $paused = \in_array($partner->getId(), $pausedIds, true);
            $expected = $paused ? Partner::STATUS_PAUSADO : Partner::STATUS_ACTIVO;
            if ($partner->getStatus() === $expected) {
                continue;
            }
            if ($input->getOption('dry-run')) {
                $changed[] = [$partner->getId(), $partner->getDisplayName() ?? $partner->getName(), $partner->getStatus() . ' → ' . $expected];
                continue;
            }
            if ($this->pauser->syncStatus($partner, $today)) {
                $changed[] = [$partner->getId(), $partner->getDisplayName() ?? $partner->getName(), $partner->getStatus()];
            }
        }

        if ($changed === []) {
            $io->success('Ninguna ficha cambia hoy.');

            return $this->nothingToDo('Ninguna pausa empieza ni termina hoy');
        }
        $io->table(['Socix', 'Nombre', 'Estado'], $changed);
        if ($input->getOption('dry-run')) {
            return self::SUCCESS;
        }
        $this->em->flush();

        return $this->didWork(sprintf('%d ficha%s al día con sus pausas', \count($changed), \count($changed) === 1 ? '' : 's'));
    }
}
