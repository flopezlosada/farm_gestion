<?php

namespace App\Command;

use App\Entity\LarProject;
use App\Repository\LarProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Siembra los proyectos reales que la asociación dinamiza hoy desde el LAR
 * (Campus Rural del MITERD e inmersiones con el IMIDRA), tal y como los describió
 * la coordinación del LAR en el correo de arranque del módulo.
 *
 * IDEMPOTENTE: crea cada proyecto solo si NO existe ya uno con el mismo título,
 * así que se puede correr varias veces sin duplicar ni pisar ediciones hechas
 * desde el panel /gestion/lar. Es el camino seguro para poblar tanto el entorno
 * local como producción (frente a un fixture, que borraría la BBDD).
 *
 * El contenido es un BORRADOR honesto derivado de datos verificables (qué son
 * esos programas + el modelo CSA de los docs); el texto fino lo remata la
 * coordinación del LAR y los vídeos se embeben en el cuerpo (TinyMCE) cuando
 * lleguen. Por eso nacen en borrador (published=false) salvo que se pase
 * --publish; en producción conviene revisar el texto antes de publicarlos.
 */
#[AsCommand(
    name: 'app:seed-lar-projects',
    description: 'Siembra los proyectos LAR reales (Campus Rural, IMIDRA) de forma idempotente.',
)]
class SeedLarProjectsCommand extends Command
{
    /**
     * @param EntityManagerInterface $em
     * @param LarProjectRepository $projects
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LarProjectRepository $projects,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'publish',
            null,
            InputOption::VALUE_NONE,
            'Marca los proyectos como publicados (visibles en /lar). Por defecto nacen en borrador.'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publish = (bool) $input->getOption('publish');

        $created = 0;
        $skipped = 0;
        foreach ($this->definitions() as $position => $data) {
            $existing = $this->projects->findOneBy(['title' => $data['title']]);
            if ($existing !== null) {
                $io->writeln(sprintf('  <comment>ya existe</comment>: %s', $data['title']));
                ++$skipped;
                continue;
            }

            $project = (new LarProject())
                ->setTitle($data['title'])
                ->setType($data['type'])
                ->setSummary($data['summary'])
                ->setContent($data['content'])
                ->setStatus(LarProject::STATUS_ACTIVE)
                ->setPublished($publish)
                ->setPosition($position);

            $this->em->persist($project);
            $io->writeln(sprintf('  <info>creado</info>: %s', $data['title']));
            ++$created;
        }

        $this->em->flush();

        $io->success(sprintf(
            'Proyectos LAR sembrados: %d creados, %d ya existían%s.',
            $created,
            $skipped,
            $publish ? ' (publicados)' : ' (en borrador)'
        ));

        return Command::SUCCESS;
    }

    /**
     * Proyectos reales del LAR, en orden de aparición. El cuerpo es un borrador
     * factual; la coordinación del LAR lo afina y añade los vídeos por el panel.
     *
     * @return array<int, array{title: string, type: string, summary: string, content: string}>
     */
    private function definitions(): array
    {
        return [
            [
                'title' => 'Campus Rural',
                'type' => 'campus_rural',
                'summary' => 'Estancias de inmersión agroecológica con estudiantado de universidades españolas, en el marco del programa Campus Rural del Ministerio para la Transición Ecológica y el Reto Demográfico (MITERD).',
                'content' => '<p>En el marco del programa <strong>Campus Rural</strong>, impulsado por el Ministerio para la Transición Ecológica y el Reto Demográfico (MITERD), acogemos a estudiantado de universidades españolas para realizar estancias de inmersión en nuestra Comunidad que Sostiene la Agricultura.</p>'
                    . '<p>Durante la estancia, el alumnado se aproxima de primera mano al modelo CSA: la producción hortícola en las fincas de El Sotillo y La Barca, la ganadería aviar, el aprovechamiento de los residuos de la huerta mediante compostaje, y la elaboración y el reparto de las cestas a la comunidad.</p>'
                    . '<p>La duración y los contenidos se adaptan a cada convocatoria y al perfil del grupo.</p>',
            ],
            [
                'title' => 'Inmersiones con el IMIDRA',
                'type' => 'formacion_imidra',
                'summary' => 'Inmersiones intensivas de formación agroecológica en colaboración con el IMIDRA (Instituto Madrileño de Investigación y Desarrollo Rural, Agrario y Alimentario).',
                'content' => '<p>Junto al <strong>IMIDRA</strong> (Instituto Madrileño de Investigación y Desarrollo Rural, Agrario y Alimentario) desarrollamos inmersiones intensivas de formación agroecológica en el espacio de La Cerrada.</p>'
                    . '<p>Son experiencias prácticas de aproximación al modelo de las Comunidades que Sostienen la Agricultura, que combinan el trabajo en las fincas con el conocimiento de las dinámicas internas de la CSA.</p>'
                    . '<p>La duración y los contenidos se ajustan a la demanda de cada grupo.</p>',
            ],
        ];
    }
}
