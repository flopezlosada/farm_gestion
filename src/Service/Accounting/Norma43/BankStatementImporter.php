<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\BankStatement;
use App\Entity\BankStatementLine;
use App\Entity\FinancialAccount;
use App\Entity\IssuedInvoice;
use App\Entity\User;
use App\Repository\BankStatementLineRepository;
use App\Repository\BankStatementRepository;
use App\Repository\FinancialAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Guarda un extracto ya leído en la cuenta del libro que eligió quien lo sube, guarda
 * sus movimientos sin repetir los que ya estaban, liga los que no dejan duda
 * ({@see BankLineMatcher}) y anota solos los que el libro aún no tiene y no dejan duda:
 * la liquidación de una tarjeta ({@see CardSettlementBooker}), el abono de una remesa
 * enviada ({@see RemittanceCreditBooker}) y lo demás
 * ({@see BankLineBooker}).
 *
 * Antes de guardar nada comprueba que el fichero es de esa cuenta
 * ({@see assertFits()}): el IBAN, si el fichero lo trae; que su saldo inicial sigue
 * al del último extracto; y que sus movimientos no se subieron ya a otra cuenta. Sólo
 * se ve si algo falla.
 */
class BankStatementImporter
{
    public function __construct(
        private readonly FinancialAccountRepository $accounts,
        private readonly BankStatementRepository $statements,
        private readonly BankStatementLineRepository $lines,
        private readonly BankLineMatcher $matcher,
        private readonly BankLineBooker $booker,
        private readonly CardSettlementBooker $cards,
        private readonly RemittanceCreditBooker $remittanceCredits,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Las cuentas a las que se puede subir un extracto: de banco y activas.
     *
     * @return list<FinancialAccount>
     */
    public function uploadableAccounts(): array
    {
        return array_values(array_filter($this->accounts->findActive(), static fn (FinancialAccount $a): bool => $a->isBank()));
    }

    /**
     * Guarda el extracto en la cuenta elegida. Todo o nada: si algo no cuadra, no se
     * guarda nada.
     *
     * Volver a subir el mismo extracto no crea otro ni duplica movimientos; uno que se
     * solapa con otro sólo añade los movimientos nuevos. Si ya hay un extracto de esa
     * cuenta para esas mismas fechas con OTROS saldos, se rechaza: uno de los dos está
     * mal y no se puede saber cuál.
     *
     * @param Norma43File      $file
     * @param FinancialAccount $account  La cuenta del libro que eligió quien lo sube.
     * @param string           $fileName Nombre del fichero subido.
     * @param User|null        $by       Quién lo sube.
     *
     * @throws Norma43Exception Si el fichero no es de esa cuenta o no sigue a lo ya subido.
     */
    public function import(Norma43File $file, FinancialAccount $account, string $fileName, ?User $by): BankStatementImport
    {
        [$read, $existing] = $this->assertFits($file, $account);
        $statement = $existing ?? new BankStatement($account, $read, $fileName, $by);

        $candidates = self::fingerprints($account, $read);
        $known = $this->lines->existingFingerprints(array_column($candidates, 1));
        $fresh = [];
        foreach ($candidates as [$movement, $fingerprint, $ordinal]) {
            if (!isset($known[$fingerprint])) {
                $known[$fingerprint] = true;
                $fresh[] = new BankStatementLine($account, $movement, $ordinal);
            }
        }

        // Una transacción: los movimientos, el extracto, lo ligado y lo anotado, o nada.
        [$linked, $booked] = $this->em->wrapInTransaction(function () use ($statement, $fresh, $by): array {
            $this->em->persist($statement);
            array_walk($fresh, fn (BankStatementLine $l) => $this->em->persist($l));
            $this->em->flush();

            return $this->crossWithBook([$statement], $by);
        });

        return new BankStatementImport([$statement], \count($fresh), \count($candidates) - \count($fresh), $linked, $file->warnings, $booked);
    }

    /**
     * Que el fichero es de esa cuenta y sigue a lo ya subido. Devuelve la cuenta leída
     * y el extracto ya subido de esas mismas fechas, si lo hay.
     *
     *  - Una sola cuenta en el fichero: con varias no se sabe cuál es la elegida.
     *  - De banco y activa.
     *  - IBAN: si el fichero lo trae (Caixa sí, la hoja de Fiare no), ni es el de otra
     *    cuenta del libro ni contradice el de ésta.
     *  - Mismas fechas que uno ya subido: los mismos saldos. Si no, el saldo inicial es
     *    el del último extracto: el final, si éste acaba antes; el inicial, si empieza el
     *    mismo día (el fichero del año, ahora más largo). Si es de antes que lo ya
     *    subido, su saldo final es el inicial del siguiente. Si se solapan a medias no
     *    hay con qué compararlo.
     *  - Más de la mitad de sus movimientos no están ya en otra cuenta: sería el
     *    fichero de esa otra.
     *
     * @return array{0: Norma43Account, 1: ?BankStatement}
     *
     * @throws Norma43Exception
     */
    private function assertFits(Norma43File $file, FinancialAccount $account): array
    {
        if (\count($file->accounts) !== 1) {
            throw new Norma43Exception(sprintf('El fichero trae %d cuentas. Bájalo de una sola, la de «%s».', \count($file->accounts), $account->getName()));
        }
        $read = $file->accounts[0];
        if (!$account->isBank() || !$account->isActive()) {
            throw new Norma43Exception(sprintf('«%s» no es una cuenta de banco activa: no se le puede subir un extracto.', $account->getName()));
        }
        $this->assertSameIban($read, $account);
        $same = $this->statements->findSamePeriod($account, $read->from, $read->to);
        $this->assertContinues($read, $account, $same);
        $this->assertNotInOtherAccount($read, $account);

        return [$read, $same];
    }

    /** @throws Norma43Exception Si el IBAN del fichero es de otra cuenta o no es el de ésta. */
    private function assertSameIban(Norma43Account $read, FinancialAccount $account): void
    {
        if (!$read->isKnown()) {
            return;
        }
        foreach ($this->accounts->findAll() as $other) {
            /** @var FinancialAccount $other */
            if ($other->getId() !== $account->getId() && $other->getBankKey() === $read->key()) {
                throw new Norma43Exception(sprintf('El fichero es de la %s, que en el libro es «%s», no «%s». Súbelo en «%s».', $read->label(), $other->getName(), $account->getName(), $other->getName()));
            }
        }
        if ($account->getBankKey() !== null && $account->getBankKey() !== $read->key()) {
            throw new Norma43Exception(sprintf('El fichero es de la %s, y «%s» tiene otro IBAN. ¿Es el extracto de otra cuenta?', $read->label(), $account->getName()));
        }
    }

    /**
     * @param BankStatement|null $same El ya subido de esta cuenta con las mismas fechas.
     *
     * @throws Norma43Exception Si el saldo inicial no sigue al de lo ya subido de esta cuenta.
     */
    private function assertContinues(Norma43Account $read, FinancialAccount $account, ?BankStatement $same): void
    {
        if ($same !== null) {
            if (!$same->agreesWith($read)) {
                throw new Norma43Exception(sprintf(
                    'Ya hay un extracto de «%s» del %s al %s con otros saldos (empieza en %s y acaba en %s). Uno de los dos está mal.',
                    $account->getName(), $read->from->format('d/m/Y'), $read->to->format('d/m/Y'),
                    self::money(IssuedInvoice::toCents($same->getOpeningBalance())), self::money(IssuedInvoice::toCents($same->getClosingBalance())),
                ));
            }

            return;
        }
        $previous = $this->statements->findLatestStartingBy($account, $read->from);
        $this->assertFollowedBy($read, $account, $previous);
        if ($previous === null) {
            return;
        }
        $continues = $previous->getTo() < $read->from;
        if (!$continues && $previous->getFrom() != $read->from) {
            return;
        }
        $expected = IssuedInvoice::toCents($continues ? $previous->getClosingBalance() : $previous->getOpeningBalance());
        if ($expected !== $read->openingCents) {
            throw new Norma43Exception(sprintf(
                'El fichero empieza el %s con %s, y el último extracto de «%s» (del %s al %s) %s con %s. %s',
                $read->from->format('d/m/Y'), self::money($read->openingCents), $account->getName(),
                $previous->getFrom()->format('d/m/Y'), $previous->getTo()->format('d/m/Y'),
                $continues ? 'acaba' : 'empieza ese mismo día', self::money($expected),
                $continues ? 'Falta algún movimiento entre los dos o el fichero no es de esta cuenta.' : 'El fichero no es de esta cuenta o uno de los dos está mal.',
            ));
        }
    }

    /**
     * Un mes ANTERIOR a lo ya subido: su saldo final es el inicial del extracto que le
     * sigue. Sólo si no se solapa con ninguno: dentro del fichero del año no hay con qué
     * compararlo.
     *
     * @param BankStatement|null $previous El último que empieza el mismo día o antes.
     *
     * @throws Norma43Exception Si el saldo final no lleva al del extracto siguiente.
     */
    private function assertFollowedBy(Norma43Account $read, FinancialAccount $account, ?BankStatement $previous): void
    {
        $next = $this->statements->findFirstStartingAfter($account, $read->from);
        $overlaps = ($previous !== null && $previous->getTo() >= $read->from) || ($next !== null && $next->getFrom() <= $read->to);
        if ($next === null || $overlaps) {
            return;
        }
        $expected = IssuedInvoice::toCents($next->getOpeningBalance());
        if ($expected !== $read->closingCents) {
            throw new Norma43Exception(sprintf(
                'El fichero acaba el %s con %s, y el extracto siguiente de «%s» (del %s al %s) empieza con %s. Falta algún movimiento entre los dos o el fichero no es de esta cuenta.',
                $read->to->format('d/m/Y'), self::money($read->closingCents), $account->getName(),
                $next->getFrom()->format('d/m/Y'), $next->getTo()->format('d/m/Y'), self::money($expected),
            ));
        }
    }

    /** @throws Norma43Exception Si más de la mitad de los movimientos ya están en otra cuenta. */
    private function assertNotInOtherAccount(Norma43Account $read, FinancialAccount $account): void
    {
        $others = array_values(array_filter($this->uploadableAccounts(), static fn (FinancialAccount $a): bool => $a->getId() !== $account->getId()));
        if ($read->movements === [] || $others === []) {
            return;
        }
        $byFingerprint = [];
        foreach ($others as $other) {
            foreach (self::fingerprints($other, $read) as [, $fingerprint]) {
                $byFingerprint[$fingerprint] = $other;
            }
        }
        $hits = [];
        foreach (array_keys($this->lines->existingFingerprints(array_keys($byFingerprint))) as $fingerprint) {
            $other = $byFingerprint[$fingerprint];
            $hits[spl_object_id($other)] = [$other, ($hits[spl_object_id($other)][1] ?? 0) + 1];
        }
        foreach ($hits as [$other, $count]) {
            if ($count * 2 > \count($read->movements)) {
                throw new Norma43Exception(sprintf('%d de los %d movimientos del fichero ya se subieron a «%s». ¿Es el extracto de «%s» y no de «%s»?', $count, \count($read->movements), $other->getName(), $other->getName(), $account->getName()));
            }
        }
    }

    /**
     * Cada movimiento con su huella en esa cuenta y su posición entre los idénticos del
     * mismo día ({@see BankStatementLine::fingerprint()}).
     *
     * @return list<array{0: Norma43Movement, 1: string, 2: int}>
     */
    private static function fingerprints(FinancialAccount $account, Norma43Account $read): array
    {
        $ordinals = [];

        return array_map(static function (Norma43Movement $movement) use ($account, &$ordinals): array {
            $ordinal = $ordinals[$movement->identity()] = ($ordinals[$movement->identity()] ?? 0) + 1;

            return [$movement, BankStatementLine::fingerprint($account, $movement, $ordinal), $ordinal];
        }, $read->movements);
    }

    /** Un saldo en céntimos, para los mensajes: «1.234,56 €». */
    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }

    /**
     * Vuelve a cruzar un extracto ya subido con el libro, como al subirlo: lo que se
     * anotó después, lo que el cruce de entonces aún no sabía juntar y lo que ahora sí
     * se sabe anotar solo.
     *
     * @param User|null $by Quien lo pide.
     *
     * @return array{0: int, 1: int} Cuántos movimientos se han ligado y cuántos anotado.
     */
    public function relink(BankStatement $statement, ?User $by): array
    {
        return $this->em->wrapInTransaction(fn (): array => $this->crossWithBook([$statement], $by));
    }

    /**
     * Cruza los movimientos sin apunte de estos extractos con el libro: primero se
     * ligan a los apuntes que ya los tenían ({@see BankLineMatcher}); con lo que queda,
     * la app anota sola lo que no deja duda ({@see BankLineBooker}). Guarda.
     *
     * @param list<BankStatement> $statements
     * @param User|null           $by         Quien sube o vuelve a cruzar.
     *
     * @return array{0: int, 1: int} Cuántos se han ligado y cuántos anotado.
     */
    private function crossWithBook(array $statements, ?User $by): array
    {
        $unlinked = $this->unlinkedAround($statements);
        $linked = $this->matcher->linkSafe($unlinked);
        $this->em->flush();
        // Con un apunte posible a pocos días, el libro ya lo tiene: anotarlo otra vez lo
        // duplicaría. Ése se confirma a mano («Es éste»).
        $rest = array_values(array_filter($this->within($unlinked, $statements), static fn (BankStatementLine $l): bool => $l->getEntry() === null));
        $bookable = array_column(array_filter($this->matcher->candidatesFor($rest), static fn (array $row): bool => $row['candidates'] === []), 'line');
        // Primero los que se saben por otra cosa que el concepto: la liquidación de la
        // tarjeta es un traspaso y el abono de una remesa lleva la partida de la remesa.
        // Cada uno sólo anota movimientos aún sin apunte, así que no se pisan.
        $booked = \count($this->cards->bookSafe($bookable, $by))
            + \count($this->remittanceCredits->bookSafe($bookable, $by));
        $booked += \count($this->booker->bookSafe(array_values(array_filter($bookable, static fn (BankStatementLine $l): bool => $l->getEntry() === null)), $by));
        $this->em->flush();

        return [$linked, $booked];
    }

    /**
     * De unos movimientos, los de las fechas de estos extractos: los vecinos de unos días
     * antes y después sirven para emparejar, pero se anotan cuando se sube su extracto.
     *
     * @param list<BankStatementLine> $lines
     * @param list<BankStatement>     $statements
     *
     * @return list<BankStatementLine>
     */
    private function within(array $lines, array $statements): array
    {
        return array_values(array_filter($lines, static fn (BankStatementLine $l): bool => array_filter(
            $statements,
            static fn (BankStatement $s): bool => $s->getAccount() === $l->getAccount() && $l->getOperationDate() >= $s->getFrom() && $l->getOperationDate() <= $s->getTo(),
        ) !== []));
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
