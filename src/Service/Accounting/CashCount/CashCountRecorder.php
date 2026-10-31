<?php

namespace App\Service\Accounting\CashCount;

use App\Entity\CashCount;
use App\Entity\FinancialAccount;
use App\Entity\User;
use App\Repository\AccountEntryRepository;
use App\Repository\CashCountRepository;
use App\Repository\FinancialAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\UnicodeString;

/**
 * Registra un arqueo de caja: busca la caja por su nombre, lo compara con el libro y lo
 * guarda, cuadre o no. Si no cuadra, lo avisa la portada de contabilidad
 * ({@see \App\Service\Accounting\Agenda\AccountingAgenda}); nunca se hace un apunte
 * para que cuadre: la diferencia la explica una persona.
 *
 * El nombre de la caja no se adivina. Se compara sin mayúsculas ni tildes y sin las
 * palabras que no distinguen («caja», «de», «la»…): «caja huerta» es «Caja de la
 * huerta». Si encaja con más de una, o con ninguna, se contesta con la lista.
 */
class CashCountRecorder
{
    /** Palabras que no distinguen una caja de otra. */
    private const FILLER_WORDS = ['caja', 'de', 'del', 'la', 'el', 'los', 'las'];

    /**
     * @param FinancialAccountRepository $accounts Las cajas.
     * @param AccountEntryRepository     $entries  El libro, para el saldo.
     * @param CashCountRepository        $counts   Los arqueos ya registrados.
     * @param EntityManagerInterface     $em       Para guardar.
     */
    public function __construct(
        private readonly FinancialAccountRepository $accounts,
        private readonly AccountEntryRepository $entries,
        private readonly CashCountRepository $counts,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Registra un arqueo. Si ese mensaje de Telegram ya se registró (Telegram repite
     * la entrega), devuelve el que hay en vez de duplicarlo.
     *
     * @param CashCountMessage   $message    Lo entendido del mensaje.
     * @param User|null          $countedBy  Quién lo contó.
     * @param \DateTimeImmutable $receivedAt Cuándo llegó.
     * @param string             $text       El mensaje tal cual.
     * @param int|null           $updateId   Su `update_id` de Telegram, si vino por ahí.
     *
     * @throws CashCountRejected Si la caja no se sabe cuál es, está retirada o la fecha es anterior a su apertura.
     */
    public function record(CashCountMessage $message, ?User $countedBy, \DateTimeImmutable $receivedAt, string $text, ?int $updateId): RecordedCashCount
    {
        $already = $updateId !== null ? $this->counts->findByTelegramUpdateId($updateId) : null;
        if ($already !== null) {
            return new RecordedCashCount($already, $this->entries->dayBalances($already->getAccount(), $already->getCountDate()));
        }

        $account = $this->findCashAccount($message->accountName);
        if (!$account->isOpenOn($message->date)) {
            throw new CashCountRejected(sprintf(
                '%s se lleva en la web desde el %s: un arqueo de antes no se puede comparar con el libro.',
                $account->getName(),
                $account->getOpeningDate()?->format('d/m/Y'),
            ));
        }

        $balances = $this->entries->dayBalances($account, $message->date);
        $count = new CashCount(
            $account,
            $message->date,
            $message->countedCents,
            $balances->comparedWith($message->countedCents),
            $countedBy,
            $receivedAt,
            $text,
            $updateId,
        );
        $this->em->persist($count);
        $this->em->flush();

        return new RecordedCashCount($count, $balances);
    }

    /**
     * La caja de efectivo abierta con ese nombre.
     *
     * @param string $name El nombre tal como se escribió («huerta», «Caja de la Huerta»).
     *
     * @throws CashCountRejected Si no hay ninguna, hay varias o está retirada; el mensaje lleva las cajas abiertas.
     */
    public function findCashAccount(string $name): FinancialAccount
    {
        $wanted = self::words($name);
        $cash = $this->accounts->findBy(['kind' => FinancialAccount::KIND_CASH], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        $active = array_values(array_filter($cash, static fn (FinancialAccount $a): bool => $a->isActive()));

        $matching = self::matching($active, $wanted);
        if (\count($matching) === 1) {
            return $matching[0];
        }
        if (\count($matching) > 1) {
            throw new CashCountRejected(sprintf('«%s» puede ser %s. Escribe el nombre entero: «caja %s 400,56».', $name, self::listed($matching, 'o'), mb_strtolower($matching[0]->getName())));
        }

        $retired = self::matching(array_values(array_filter($cash, static fn (FinancialAccount $a): bool => !$a->isActive())), $wanted);
        $opening = $retired !== []
            ? sprintf('%s está retirada: ya no se cuenta.', $retired[0]->getName())
            : sprintf('No tengo ninguna caja que se llame «%s».', $name);

        throw new CashCountRejected($active === []
            ? $opening . ' Ahora mismo no hay ninguna caja de efectivo abierta.'
            : sprintf('%s Las cajas abiertas son %s.', $opening, self::listed($active, 'y')));
    }

    /**
     * Las cajas cuyo nombre lleva todas las palabras buscadas. Si una las lleva justas
     * (ni una más), es esa aunque otras también las lleven: «caja local» es «Caja del
     * local» y no «Caja del local viejo».
     *
     * @param list<FinancialAccount> $accounts Dónde buscar.
     * @param list<string>           $wanted   Palabras buscadas, ya normalizadas.
     *
     * @return list<FinancialAccount>
     */
    private static function matching(array $accounts, array $wanted): array
    {
        if ($wanted === []) {
            return [];
        }
        $containing = array_values(array_filter(
            $accounts,
            static fn (FinancialAccount $a): bool => array_diff($wanted, self::words($a->getName())) === [],
        ));
        $exact = array_values(array_filter(
            $containing,
            static fn (FinancialAccount $a): bool => array_diff(self::words($a->getName()), $wanted) === [],
        ));

        return \count($exact) === 1 ? $exact : $containing;
    }

    /**
     * Las palabras de un nombre que lo distinguen, sin mayúsculas ni tildes.
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $plain = (new UnicodeString($name))->ascii()->lower()->toString();
        $words = preg_split('/[^a-z0-9]+/', $plain, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_diff($words, self::FILLER_WORDS));
    }

    /**
     * «A», «A y B», «A, B y C».
     *
     * @param list<FinancialAccount> $accounts Las cajas.
     * @param string                 $last     La conjunción del final: «y» u «o».
     */
    private static function listed(array $accounts, string $last): string
    {
        $names = array_map(static fn (FinancialAccount $a): string => '«' . $a->getName() . '»', $accounts);
        $final = array_pop($names);

        return $names === [] ? (string) $final : implode(', ', $names) . ' ' . $last . ' ' . $final;
    }
}
