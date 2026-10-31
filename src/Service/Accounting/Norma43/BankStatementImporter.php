<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\BankStatement;
use App\Entity\BankStatementLine;
use App\Entity\FinancialAccount;
use App\Entity\User;
use App\Repository\BankStatementLineRepository;
use App\Repository\BankStatementRepository;
use App\Repository\FinancialAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Guarda un extracto ya leído: decide de qué cuenta del libro es cada cuenta del
 * fichero, guarda sus movimientos sin repetir los que ya estaban y liga los que no dejan
 * duda ({@see BankLineMatcher}).
 *
 * La cuenta se reconoce por el IBAN de la ficha (entidad, oficina y cuenta). Si ninguna
 * lo tiene, la elige una persona entre las cuentas de banco SIN IBAN: adivinar por los
 * últimos dígitos podría meter los movimientos en otra cuenta, y una con otro IBAN es,
 * por definición, otra cuenta.
 */
class BankStatementImporter
{
    public function __construct(
        private readonly FinancialAccountRepository $accounts,
        private readonly BankStatementRepository $statements,
        private readonly BankStatementLineRepository $lines,
        private readonly BankLineMatcher $matcher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Las cuentas que se pueden elegir para un extracto que no casa por IBAN: de banco,
     * activas y sin IBAN español.
     *
     * @return list<FinancialAccount>
     */
    public function choosableAccounts(): array
    {
        return array_values(array_filter(
            $this->accounts->findActive(),
            static fn (FinancialAccount $a): bool => $a->isBank() && $a->getBankKey() === null,
        ));
    }

    /**
     * De qué cuenta del libro es cada cuenta del fichero.
     *
     * @param Norma43File        $file
     * @param array<string, int> $chosen Id de cuenta elegida por la persona, por clave de la cuenta del fichero.
     *
     * @return array{resolved: array<string, FinancialAccount>, unresolved: list<Norma43Account>}
     *
     * @throws Norma43Exception Si alguna es de una cuenta retirada o que no es de banco, o la elegida no vale.
     */
    public function resolve(Norma43File $file, array $chosen = []): array
    {
        $byKey = [];
        foreach ($this->accounts->findAll() as $account) {
            /** @var FinancialAccount $account */
            if (($key = $account->getBankKey()) !== null) {
                $byKey[$key][] = $account;
            }
        }
        $choosable = [];
        foreach ($this->choosableAccounts() as $account) {
            $choosable[(int) $account->getId()] = $account;
        }

        $resolved = [];
        $unresolved = [];
        foreach ($file->accounts as $read) {
            $matches = $byKey[$read->key()] ?? [];
            if (\count($matches) > 1) {
                throw new Norma43Exception(sprintf('Hay %d cuentas en el libro con el IBAN de la %s: deja el IBAN sólo en la buena (Ajustes → Cuentas).', \count($matches), $read->label()));
            }
            $account = $matches[0] ?? (isset($chosen[$read->key()]) ? ($choosable[$chosen[$read->key()]] ?? null) : null);
            if ($matches === [] && isset($chosen[$read->key()]) && $account === null) {
                throw new Norma43Exception(sprintf('La cuenta elegida para la %s no vale: tiene que ser una cuenta de banco activa y sin IBAN.', $read->label()));
            }
            if ($account === null) {
                $unresolved[] = $read;
                continue;
            }
            if (!$account->isBank()) {
                throw new Norma43Exception(sprintf('El extracto de la %s es de «%s», que en el libro no es una cuenta de banco. No se ha guardado nada.', $read->label(), $account->getName()));
            }
            if (!$account->isActive()) {
                throw new Norma43Exception(sprintf('El extracto de la %s es de «%s», que está retirada. No se ha guardado nada: si vuelve a usarse, reactívala antes en Ajustes → Cuentas.', $read->label(), $account->getName()));
            }
            foreach ($resolved as $otherKey => $other) {
                if ($other === $account && $otherKey !== $read->key()) {
                    throw new Norma43Exception(sprintf('Dos cuentas del fichero irían a «%s». Elige una distinta para cada una.', $account->getName()));
                }
            }
            $resolved[$read->key()] = $account;
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    /**
     * Guarda el extracto. Todo o nada: si una cuenta falla, no se guarda ninguna.
     *
     * Volver a subir el mismo extracto no crea otro ni duplica movimientos; uno que se
     * solapa con otro sólo añade los movimientos nuevos. Si ya hay un extracto de esa
     * cuenta para esas mismas fechas con OTROS saldos, se rechaza: uno de los dos está
     * mal y no se puede saber cuál.
     *
     * @param Norma43File                     $file
     * @param array<string, FinancialAccount> $resolved Cuenta del libro por clave de la cuenta del fichero (todas).
     * @param string                          $fileName Nombre del fichero subido.
     * @param User|null                       $by       Quién lo sube.
     *
     * @throws Norma43Exception
     */
    public function import(Norma43File $file, array $resolved, string $fileName, ?User $by): BankStatementImport
    {
        $statements = [];
        $candidates = [];
        foreach ($file->accounts as $read) {
            $account = $resolved[$read->key()] ?? throw new \LogicException('Falta la cuenta del libro de la '.$read->label().'.');
            $existing = $this->statements->findSamePeriod($account, $read->from, $read->to);
            if ($existing !== null && !$existing->agreesWith($read)) {
                throw new Norma43Exception(sprintf(
                    'Ya hay un extracto de «%s» del %s al %s con otros saldos (empieza en %s y acaba en %s). Uno de los dos está mal: no se ha guardado nada.',
                    $account->getName(),
                    $read->from->format('d/m/Y'),
                    $read->to->format('d/m/Y'),
                    number_format((float) $existing->getOpeningBalance(), 2, ',', '.'),
                    number_format((float) $existing->getClosingBalance(), 2, ',', '.'),
                ));
            }
            $statements[] = $existing ?? new BankStatement($account, $read, $fileName, $by);

            $ordinals = [];
            foreach ($read->movements as $movement) {
                $ordinal = $ordinals[$movement->identity()] = ($ordinals[$movement->identity()] ?? 0) + 1;
                $candidates[] = [$account, $movement, BankStatementLine::fingerprint($account, $movement, $ordinal), $ordinal];
            }
        }

        $known = $this->lines->existingFingerprints(array_column($candidates, 2));
        $fresh = [];
        foreach ($candidates as [$account, $movement, $fingerprint, $ordinal]) {
            if (!isset($known[$fingerprint])) {
                $known[$fingerprint] = true;
                $fresh[] = new BankStatementLine($account, $movement, $ordinal);
            }
        }

        // Una transacción: los movimientos, los extractos y lo ligado, o nada.
        $linked = $this->em->wrapInTransaction(function () use ($statements, $fresh): int {
            array_walk($statements, fn (BankStatement $s) => $this->em->persist($s));
            array_walk($fresh, fn (BankStatementLine $l) => $this->em->persist($l));
            $this->em->flush();
            $linked = $this->matcher->linkSafe($this->unlinkedAround($statements));
            $this->em->flush();

            return $linked;
        });

        return new BankStatementImport($statements, \count($fresh), \count($candidates) - \count($fresh), $linked, $file->warnings);
    }

    /**
     * Los movimientos sin apunte de estos extractos, y de unos días antes y después:
     * los vecinos sin ligar cuentan para saber si un apunte es candidato de uno solo.
     *
     * @param list<BankStatement> $statements
     *
     * @return list<BankStatementLine>
     */
    private function unlinkedAround(array $statements): array
    {
        $margin = sprintf('%d days', BankLineMatcher::SUGGEST_DAYS);
        $found = [];
        foreach ($statements as $statement) {
            foreach ($this->lines->findUnlinkedIn($statement->getAccount(), $statement->getFrom()->modify('-'.$margin), $statement->getTo()->modify('+'.$margin)) as $line) {
                $found[spl_object_id($line)] = $line;
            }
        }

        return array_values($found);
    }
}
