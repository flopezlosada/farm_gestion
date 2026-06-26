<?php

namespace App\Command;

use App\Entity\PartnerBasketShare;
use App\Service\Partner\BasketPricing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recalcula la cuota mensual congelada (month_price + egg_month_price) de las
 * cestas ACTIVAS según el catálogo actual. Se ejecuta tras cambiar los precios
 * del catálogo (la cuota se guarda como foto en cada socio y no se actualiza sola).
 *
 * Salvaguarda de la CESTA GRATUITA: `isFreeBasket` no se persiste, así que una
 * cesta gratis es indistinguible de un PBS con precio 0. Para no cobrarles sin
 * querer, se SALTAN los PBS que hoy están a 0 pero cuyo catálogo daría > 0
 * (señal de cesta gratis); se listan para que se revisen a mano.
 *
 * Por seguridad sólo informa (dry-run); escribe con --force.
 */
#[AsCommand(
    name: 'app:recalc-basket-prices',
    description: 'Recalcula las cuotas congeladas de las cestas activas según el catálogo actual.',
)]
class RecalcBasketPricesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BasketPricing $basketPricing,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Escribe los nuevos precios (sin esto, sólo informa).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $shares = $this->em->getRepository(PartnerBasketShare::class)->findBy(['is_active' => true]);
        $io->title(sprintf('Recálculo de cuotas — %d cestas activas', count($shares)));

        $changed = $skippedFree = 0;
        $samples = [];
        foreach ($shares as $pbs) {
            // Comparación en cadena con 2 decimales (representación canónica del
            // DECIMAL): evita falsos positivos/negativos por redondeo de floats.
            $bs = $pbs->getBasketShare();
            $oldVeg = $this->money($pbs->getMonthPrice());
            $oldEgg = $this->money($pbs->getEggMonthPrice());
            $newVeg = $bs !== null ? $this->money($this->basketPricing->vegMonthPrice($bs, (int) $pbs->getAmount())) : '0.00';
            $newEgg = $this->money($this->basketPricing->eggMonthPrice($pbs->getEggAmount(), $pbs->getEggPeriod()));

            // Cesta gratis (a 0 pese a tener catálogo con precio): no tocar.
            if ($oldVeg === '0.00' && $oldEgg === '0.00' && ($newVeg !== '0.00' || $newEgg !== '0.00')) {
                $skippedFree++;
                continue;
            }

            if ($newVeg === $oldVeg && $newEgg === $oldEgg) {
                continue;
            }

            if (count($samples) < 15) {
                $samples[] = [
                    (string) $pbs->getPartner()?->getName(),
                    sprintf('%s → %s', $oldVeg, $newVeg),
                    sprintf('%s → %s', $oldEgg, $newEgg),
                ];
            }
            if ($force) {
                $this->basketPricing->applyTo($pbs);
            }
            $changed++;
        }

        if ($samples !== []) {
            $io->table(['Socix', 'Verdura', 'Huevos'], $samples);
            if ($changed > count($samples)) {
                $io->writeln(sprintf('  … y %d más.', $changed - count($samples)));
            }
        }

        if ($force) {
            $this->em->flush();
            $io->success(sprintf('%d cuotas actualizadas. %d cestas gratis preservadas.', $changed, $skippedFree));
        } else {
            $io->warning(sprintf('Dry-run: %d cuotas cambiarían, %d cestas gratis se preservarían. Añade --force para escribir.', $changed, $skippedFree));
        }

        return Command::SUCCESS;
    }

    /** Normaliza un precio (string o null de la BD) a cadena con 2 decimales. */
    private function money(?string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
