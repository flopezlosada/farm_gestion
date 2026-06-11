<?php

namespace App\Command;

use App\Entity\Partner;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Anonimiza los emails del snapshot de prod ANTES de subirlo a staging, para
 * que los correos (magic-link incluido) jamás lleguen a un socio real. El
 * mailer de staging entrega de verdad (SMTP real), así que esta es la única
 * red de seguridad fiable: si el no-tester tiene email inentregable, da igual
 * lo que dispare la app.
 *
 * Preserva intactos los emails de los testers que se le pasen (los que SÍ
 * deben recibir su magic-link). Al resto les reescribe el email a un dominio
 * reservado e inentregable (.invalid, RFC 6761), derivado del id para ser
 * único e idempotente: reejecutar el comando da exactamente el mismo
 * resultado.
 *
 * SOLO toca emails (User.email/emailCanonical, y username/usernameCanonical
 * cuando son un email; Partner.email/email2). No toca nombre, DNI, dirección
 * ni IBAN, para que admin siga reconociendo a los socios y pueda cuadrar el
 * reparto. Pensado para correr en LOCAL contra un CLON del snapshot antes de
 * exportar (nunca contra la golden: anonimizarla destruiría los emails reales
 * de la fuente de verdad — {@see DatabaseGuard} lo impide); no necesita acceso
 * al servidor de staging.
 */
#[AsCommand(
    name: 'app:anonymize-staging',
    description: 'Faketea los emails de los no-testers en el snapshot antes de subirlo a staging.'
)]
class AnonymizeStagingCommand extends Command
{
    /** Dominio reservado e inentregable (RFC 6761) para los emails faketeados. */
    private const FAKE_DOMAIN = 'staging.invalid';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'keep',
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'Emails de los testers cuyo correo real se preserva (separados por espacios).'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Muestra qué se cambiaría sin persistir nada.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // Reescribe emails de forma irreversible: jamás contra la golden, solo contra un clon.
        if (!$dryRun) {
            DatabaseGuard::assertNotProtected($this->em->getConnection());
        }

        $keep = array_map(
            static fn (string $email): string => mb_strtolower(trim($email)),
            $input->getArgument('keep')
        );
        $keepSet = array_fill_keys($keep, true);

        $io->title('Anonimización de emails para staging');
        $io->writeln(sprintf('Preservar (%d): %s', count($keep), implode(', ', $keep)));
        if ($dryRun) {
            $io->note('DRY-RUN: no se persiste nada.');
        }

        $usersTouched = $this->anonymizeUsers($keepSet, $io, $dryRun);
        $partnersTouched = $this->anonymizePartners($keepSet, $io, $dryRun);

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s%d User y %d Partner faketeados. Testers preservados: %d.',
            $dryRun ? '[dry-run] ' : '',
            $usersTouched,
            $partnersTouched,
            count($keep)
        ));

        // Aviso de los testers que NO se han encontrado en ninguna tabla:
        // suele ser un email mal escrito que dejaría al tester sin acceso real.
        $found = $this->keepEmailsFound($keepSet);
        $missing = array_values(array_filter($keep, static fn (string $e): bool => !isset($found[$e])));
        if ($missing !== []) {
            $io->warning(sprintf(
                "Estos emails a preservar no aparecen en ningún User ni Partner (¿typo?):\n  %s",
                implode("\n  ", $missing)
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Faketea el email de los User que no estén en la lista de preservados.
     * Reescribe también username/usernameCanonical cuando son un email (común
     * cuando el login es por correo), para no dejar el email real expuesto en
     * el username. El hash de contraseña no se toca: el no-tester no va a
     * entrar, y de todas formas no es PII directa.
     *
     * @param array<string,true> $keepSet Emails a preservar, ya normalizados.
     * @param SymfonyStyle $io
     * @param bool $dryRun
     * @return int Nº de User modificados.
     */
    private function anonymizeUsers(array $keepSet, SymfonyStyle $io, bool $dryRun): int
    {
        $touched = 0;
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $email = mb_strtolower((string) $user->getEmail());
            if ($email !== '' && isset($keepSet[$email])) {
                continue;
            }

            $fake = sprintf('user-%d@%s', $user->getId(), self::FAKE_DOMAIN);
            if (!$dryRun) {
                $user->setEmail($fake);
                $user->setEmailCanonical($fake);
                if (str_contains((string) $user->getUsername(), '@')) {
                    $user->setUsername($fake);
                    $user->setUsernameCanonical($fake);
                }
            }
            $touched++;
        }

        $io->writeln(sprintf('  User a faketear: %d', $touched));

        return $touched;
    }

    /**
     * Faketea Partner.email de los socios que no estén en la lista de
     * preservados. Salta los Partner sin email (no hay nada que proteger).
     *
     * @param array<string,true> $keepSet Emails a preservar, ya normalizados.
     * @param SymfonyStyle $io
     * @param bool $dryRun
     * @return int Nº de Partner modificados.
     */
    private function anonymizePartners(array $keepSet, SymfonyStyle $io, bool $dryRun): int
    {
        $touched = 0;
        foreach ($this->em->getRepository(Partner::class)->findAll() as $partner) {
            $email = mb_strtolower((string) $partner->getemail());
            if ($email === '' || isset($keepSet[$email])) {
                continue;
            }

            if (!$dryRun) {
                $partner->setemail(sprintf('socio-%d@%s', $partner->getId(), self::FAKE_DOMAIN));
            }
            $touched++;
        }

        $io->writeln(sprintf('  Partner a faketear: %d', $touched));

        return $touched;
    }

    /**
     * De los emails a preservar, cuáles existen realmente en algún User o
     * Partner. Sirve para avisar de typos que dejarían a un tester sin su
     * email real (y por tanto sin magic-link).
     *
     * @param array<string,true> $keepSet Emails a preservar, ya normalizados.
     * @return array<string,true> Subconjunto de keepSet realmente encontrado.
     */
    private function keepEmailsFound(array $keepSet): array
    {
        $found = [];
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $email = mb_strtolower((string) $user->getEmail());
            if (isset($keepSet[$email])) {
                $found[$email] = true;
            }
        }
        foreach ($this->em->getRepository(Partner::class)->findAll() as $partner) {
            $email = mb_strtolower((string) $partner->getemail());
            if (isset($keepSet[$email])) {
                $found[$email] = true;
            }
        }

        return $found;
    }
}
