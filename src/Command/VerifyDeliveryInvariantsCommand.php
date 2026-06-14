<?php

namespace App\Command;

use App\Service\Delivery\Invariant\DeliveryInvariant;
use App\Service\Delivery\Invariant\InvariantSuite;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verificador de INVARIANTES del reparto: evalúa las leyes del dominio
 * ({@see DeliveryInvariant}, etiquetadas) sobre el estado materializado, de
 * hoy en adelante, y reporta cada violación con socio, semana y detalle.
 *
 * SOLO LECTURA: puede correr contra cualquier BBDD, la golden incluida.
 * Tres usos: a mano tras tocar generación o datos; detrás de la batería
 * sobre su clon ejercitado (los 27 jueces sobre los escenarios); y detrás
 * del cron de generación (la semana recién congelada se autoaudita).
 *
 * Un rojo no siempre es bug de código: puede ser dato sucio real. Ambos
 * interesan. Diseño completo: memoria delivery-invariants-design.
 */
#[AsCommand(
    name: 'app:verify-delivery-invariants',
    description: 'Evalúa las leyes del dominio de reparto sobre el estado actual (solo lectura).'
)]
class VerifyDeliveryInvariantsCommand extends Command
{
    /** Tope de violaciones impresas por ley para no inundar la consola. */
    private const MAX_PRINTED = 50;

    public function __construct(
        private readonly InvariantSuite $suite,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'law',
                'l',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Evaluar solo estas leyes (p. ej. -l L5 -l L15). Por defecto, todas.'
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Inicio de la ventana de validación (Y-m-d). Por defecto, hoy.'
            )
            ->addOption(
                'max',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Tope de violaciones impresas por ley (0 = sin tope). Por defecto, %d.', self::MAX_PRINTED)
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fromOption = $input->getOption('from');
        $from = $fromOption !== null
            ? new \DateTimeImmutable((string) $fromOption)
            : new \DateTimeImmutable('today');

        $maxPrinted = $input->getOption('max') !== null
            ? max(0, (int) $input->getOption('max'))
            : self::MAX_PRINTED;

        $results = $this->suite->run($from, (array) $input->getOption('law'));
        if ($results === []) {
            $io->error('Ninguna ley coincide con el filtro --law.');

            return Command::INVALID;
        }

        $io->title(sprintf('Invariantes del reparto (desde %s)', $from->format('d/m/Y')));

        $errors = 0;
        $warnings = 0;
        $brokenLaws = 0;
        foreach ($results as $result) {
            $label = sprintf('%-4s %s', $result['code'], $result['name']);
            $violations = $result['violations'];

            if ($violations === []) {
                $io->writeln(sprintf(' <info>✓</info> %s', $label));
                continue;
            }

            $isWarning = $result['severity'] === DeliveryInvariant::SEVERITY_WARNING;
            $brokenLaws++;
            if ($isWarning) {
                $warnings += count($violations);
            } else {
                $errors += count($violations);
            }

            $io->writeln(sprintf(
                ' <%s>%s</> %s — %d %s',
                $isWarning ? 'comment' : 'error',
                $isWarning ? '⚠' : '✗',
                $label,
                count($violations),
                $isWarning ? 'avisos' : 'violaciones'
            ));
            $printed = $maxPrinted === 0 ? $violations : array_slice($violations, 0, $maxPrinted);
            foreach ($printed as $violation) {
                $io->writeln('      · ' . $violation);
            }
            if (count($violations) > count($printed)) {
                $io->writeln(sprintf('      … y %d más.', count($violations) - count($printed)));
            }
        }

        $io->newLine();
        if ($errors === 0 && $warnings === 0) {
            $io->success(sprintf('Las %d leyes se cumplen.', count($results)));

            return Command::SUCCESS;
        }
        if ($errors === 0) {
            $io->warning(sprintf('%d avisos (ninguna violación dura). %d leyes limpias.', $warnings, count($results) - $brokenLaws));

            return Command::SUCCESS;
        }
        $io->error(sprintf(
            '%d violaciones%s en %d leyes. %d leyes limpias.',
            $errors,
            $warnings > 0 ? sprintf(' (+%d avisos)', $warnings) : '',
            $brokenLaws,
            count($results) - $brokenLaws
        ));

        return Command::FAILURE;
    }
}
