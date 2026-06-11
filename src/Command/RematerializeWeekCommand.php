<?php

namespace App\Command;

use App\Entity\Basket;
use App\Service\Delivery\WeekRematerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-materializa una semana A MANO: borra su piedra y la regenera desde el
 * patrón vigente (en transacción: si la regeneración falla, la piedra vieja
 * se conserva). Sustituye al SQL artesanal de "borrar los WeeklyBaskets de la
 * semana y relanzar app:generate-weekly-delivery --basket-id".
 *
 * Los overrides (extras, traslados de nodo, shifts) viven en sus tablas y se
 * re-aplican solos; las excepciones vigentes se honran (cierre global →
 * semana vacía; traslado → fechas recalculadas). El flujo de excepciones del
 * admin ya hace esto automáticamente ({@see WeekRematerializer}); este
 * comando es para correcciones manuales o datos raros.
 *
 * OJO: sobre una semana SIN piedra, esto la CREA (piedra prematura si la
 * semana es lejana). Úsese con intención.
 */
#[AsCommand(
    name: 'app:rematerialize-week',
    description: 'Borra la piedra de una semana y la regenera desde el patrón vigente.'
)]
class RematerializeWeekCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeekRematerializer $rematerializer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('basketId', InputArgument::REQUIRED, 'Id del Basket (semana/ciclo) a re-materializar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $basket = $this->em->getRepository(Basket::class)->find((int) $input->getArgument('basketId'));
        if ($basket === null) {
            $io->error('Basket no encontrado.');

            return Command::FAILURE;
        }

        $r = $this->rematerializer->rematerializeWeek($basket);
        $io->success(sprintf(
            'Semana del %s re-materializada: %d entregas borradas, %d generadas.',
            $basket->getDate()?->format('d/m/Y'),
            $r['removed'],
            $r['created']
        ));

        return Command::SUCCESS;
    }
}
