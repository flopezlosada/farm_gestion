<?php

namespace App\Command;

use App\Entity\User;
use App\Form\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migra los roles de los usuarios al partir una sección en lectura/escritura.
 *
 * Cuando una sección pasa del modelo "un rol = acceso total" al modelo
 * lectura/escritura (rol base = LECTURA, rol `_EDIT` = ESCRITURA), el rol base
 * que los usuarios YA tienen queda degradado a solo lectura. Para no quitarles
 * en silencio la escritura que tenían, este comando AÑADE el rol `_EDIT` a
 * todo usuario que tenga el rol base de la sección.
 *
 * Es ADITIVO e IDEMPOTENTE: reejecutarlo no cambia nada (salta a quien ya tiene
 * el `_EDIT`), y no toca a nadie más (los admin entran por ROLE_ADMIN vía la
 * jerarquía, no por el rol base literal, así que se quedan como están). Por eso
 * NO usa {@see DatabaseGuard}: está pensado para correr en TODAS las BBDD
 * (db, golden y, al desplegar, prod), no solo en clones.
 *
 * La sección y su par de roles se leen de {@see UserType::SECTIONS} (fuente
 * única de verdad del catálogo de secciones), así que sirve igual para socixs
 * que para las próximas secciones que se partan.
 *
 * Debe ejecutarse JUNTO al despliegue del split (mismo corte): si el código
 * sube antes que la migración, los gestores de la sección se quedan en solo
 * lectura hasta que corra.
 */
#[AsCommand(
    name: 'app:split-section-role',
    description: 'Añade el rol _EDIT a quien tenga el rol base de una sección recién partida en lectura/escritura.'
)]
class SplitSectionRoleCommand extends Command
{
    /**
     * Roles que {@see User::getRoles()} DERIVA en caliente (no se guardan en la
     * columna `roles`). Se filtran al reconstruir el conjunto almacenado para no
     * persistirlos por error.
     */
    private const DERIVED_ROLES = ['ROLE_USER', 'ROLE_PARTNER', 'ROLE_WORKER'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'section',
                InputArgument::REQUIRED,
                'Clave de la sección a migrar (p. ej. "socixs"). Debe estar partida en lectura/escritura en UserType::SECTIONS.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Muestra a quién se le añadiría el rol sin persistir nada.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $section = (string) $input->getArgument('section');

        $sections = UserType::SECTIONS;
        if (!isset($sections[$section])) {
            $io->error(sprintf(
                'Sección desconocida "%s". Válidas: %s.',
                $section,
                implode(', ', array_keys($sections))
            ));

            return Command::INVALID;
        }

        $baseRole = $sections[$section]['read'];
        $editRole = $sections[$section]['edit'];
        if ($editRole === null) {
            $io->error(sprintf(
                'La sección "%s" no está partida (edit = null): no tiene rol de escritura que migrar.',
                $section
            ));

            return Command::INVALID;
        }

        $io->title(sprintf('Migración de roles · sección "%s"', $section));
        $io->writeln(sprintf('Rol base (lectura): <info>%s</info>', $baseRole));
        $io->writeln(sprintf('Rol a añadir (escritura): <info>%s</info>', $editRole));
        if ($dryRun) {
            $io->note('DRY-RUN: no se persiste nada.');
        }

        $touched = [];
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $roles = $user->getRoles();
            // Solo quien tiene el rol base LITERAL y aún no el _EDIT. Los admin
            // no tienen el base literal (entran por jerarquía), así que se saltan.
            if (!\in_array($baseRole, $roles, true) || \in_array($editRole, $roles, true)) {
                continue;
            }

            // Reconstruye el conjunto ALMACENADO (sin los derivados) y le añade
            // el _EDIT, preservando cualquier otro rol que tuviera.
            $stored = array_values(array_diff($roles, self::DERIVED_ROLES));
            $stored[] = $editRole;

            if (!$dryRun) {
                $user->setRoles(array_values(array_unique($stored)));
            }

            $touched[] = $user->getUserIdentifier();
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        if ($touched === []) {
            $io->success('Nada que migrar: ningún usuario tiene el rol base sin el _EDIT (¿ya se corrió?).');

            return Command::SUCCESS;
        }

        $io->listing($touched);
        $io->success(sprintf(
            '%s%d usuario(s) %s %s.',
            $dryRun ? '[dry-run] ' : '',
            count($touched),
            $dryRun ? 'recibirían' : 'recibieron',
            $editRole
        ));

        return Command::SUCCESS;
    }
}
