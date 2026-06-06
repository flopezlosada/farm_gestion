<?php

namespace App\Command;

use App\Entity\Basket;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Repository\PartnerBasketShareRepository;
use App\Service\Delivery\WeeklyBasketComposer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rellena los WeeklyBasketItem (composición por componentes) de las entregas YA
 * materializadas. El generador estampa los ítems al CREAR entregas nuevas, pero
 * las que ya existen en BBDD (las semanas reconciliadas) no pasan por ahí — este
 * comando las pone al día.
 *
 * Acotado por fecha: por defecto desde HOY hacia adelante, que es lo único que
 * la Etapa 2 va a leer. El histórico lejano es más frágil (el share del socio
 * pudo cambiar desde que se materializó la entrega), así que no se toca salvo
 * que se pida con --from.
 *
 * Idempotente: el composer no duplica ítems si la entrega ya los tiene. Es
 * estrictamente aditivo — escribe en weekly_basket_item, no toca weekly_basket
 * ni el resto del reparto.
 */
#[AsCommand(
    name: 'app:backfill-weekly-basket-items',
    description: 'Estampa la composición (verdura/huevos) en las entregas ya materializadas.'
)]
class BackfillWeeklyBasketItemsCommand extends Command
{
    /** Tamaño de lote para el flush, para no inflar el unit of work. */
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeeklyBasketComposer $composer,
        private readonly PartnerBasketShareRepository $shareRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Fecha mínima (YYYY-MM-DD) de las entregas a rellenar. Por defecto, hoy.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'No escribe nada: solo informa cuántas entregas se rellenarían y cuántas se saltarían.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $fromOption = $input->getOption('from');
        try {
            $from = new \DateTimeImmutable($fromOption ?? 'today');
        } catch (\Exception) {
            $io->error(sprintf('Fecha --from inválida: %s', $fromOption));
            return Command::FAILURE;
        }

        $io->title('Backfill de composición de entregas (WeeklyBasketItem)');
        $io->text(sprintf('Entregas desde %s%s', $from->format('Y-m-d'), $dryRun ? ' · DRY-RUN' : ''));

        /** @var WeeklyBasket[] $weeklyBaskets */
        $weeklyBaskets = $this->em->createQuery(
            'SELECT wb FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             WHERE b.date >= :from
             ORDER BY b.date ASC'
        )->setParameter('from', $from->format('Y-m-d'))->getResult();

        $composed = 0;
        $skippedNoShare = 0;
        $processed = 0;

        foreach ($weeklyBaskets as $wb) {
            $basket = $wb->getBasket();
            // El share se resuelve a la fecha de la ENTREGA, no a hoy: un socio
            // de alta futura (p. ej. MARIO, alta 1-jun) tiene WB en su semana
            // pero su share no está vigente "hoy". Usar basket.date lo resuelve.
            $share = $wb->getPartnerBasketShare()
                ?? $this->shareRepository->findActiveForPartner($wb->getPartner(), $basket->getDate());

            if ($share === null) {
                // Socio sin suscripción activa (baja histórica): no se puede
                // componer. Se cuenta y se deja como está.
                $skippedNoShare++;
                continue;
            }

            if (!$dryRun) {
                $this->composer->compose($wb, $share, $basket);
                if ((++$processed % self::BATCH_SIZE) === 0) {
                    $this->em->flush();
                }
            }
            $composed++;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s %d entregas%s. Saltadas (sin share activo): %d. Total revisadas: %d.',
            $dryRun ? 'Se rellenarían' : 'Rellenadas',
            $composed,
            $dryRun ? '' : ' (composición estampada; el composer ya evita duplicados)',
            $skippedNoShare,
            count($weeklyBaskets),
        ));

        return Command::SUCCESS;
    }
}
