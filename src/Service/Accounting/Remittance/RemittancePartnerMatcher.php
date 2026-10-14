<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\Partner;
use App\Entity\RemittanceLine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pone a cada recibo importado el socix al que corresponde: primero por la cuenta,
 * que es lo fiable, y si no por el nombre.
 *
 * Sólo asigna cuando no hay duda. Una familia comparte cuenta, y el Excel escribe
 * los nombres como quiere («APELLIDOS NOMBRE» o al revés); ante dos candidatos el
 * recibo se queda sin socix y lo decide una persona en la revisión. Un socix mal
 * puesto haría que la remesa avisara de diferencias que no existen.
 */
class RemittancePartnerMatcher
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Asigna socix a los recibos que no lo tienen. Carga los socixs una sola vez.
     *
     * @param list<RemittanceLine> $lines
     *
     * @return int Cuántos recibos se han quedado sin socix.
     */
    public function match(array $lines): int
    {
        /** @var list<Partner> $partners */
        $partners = $this->em->createQueryBuilder()
            ->select('p')->from(Partner::class, 'p')
            ->getQuery()->getResult();

        return self::matchAgainst($lines, $partners);
    }

    /**
     * Lo mismo contra una lista de socixs dada.
     *
     * @param list<RemittanceLine> $lines
     * @param list<Partner>        $partners
     *
     * @return int Cuántos recibos se han quedado sin socix.
     */
    public static function matchAgainst(array $lines, array $partners): int
    {
        $byIban = self::groupBy($partners, static fn (Partner $p): string => RemittanceLine::normalizeIban($p->getIban()));
        $byName = self::groupBy($partners, static fn (Partner $p): string => self::nameKey($p->getLegalName()));

        $unmatched = 0;
        foreach (array_filter($lines, static fn (RemittanceLine $l): bool => $l->getPartner() === null) as $line) {
            $partner = self::pick($byIban[$line->getIban()] ?? []) ?? self::pick($byName[self::nameKey($line->getDebtorName())] ?? []);
            $line->setPartner($partner);
            $unmatched += $partner === null ? 1 : 0;
        }

        return $unmatched;
    }

    /**
     * Las palabras del nombre ordenadas y sin tildes: «LÓPEZ GARCÍA, ANA» y «Ana López
     * García» dan lo mismo.
     */
    public static function nameKey(string $name): string
    {
        $plain = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        $words = preg_split('/[^a-z0-9]+/', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($words);

        return implode(' ', $words);
    }

    /**
     * El único candidato, o null. Entre varios se queda con quien encabeza la familia
     * y sigue de alta, si sólo hay uno así.
     *
     * @param list<Partner> $candidates
     */
    private static function pick(array $candidates): ?Partner
    {
        if (\count($candidates) <= 1) {
            return $candidates[0] ?? null;
        }
        $heads = array_values(array_filter(
            $candidates,
            static fn (Partner $p): bool => $p->getParent() === null && $p->getStatus() !== Partner::STATUS_BAJA,
        ));

        return \count($heads) === 1 ? $heads[0] : null;
    }

    /**
     * @param list<Partner>             $partners
     * @param callable(Partner): string $key
     *
     * @return array<string, list<Partner>>
     */
    private static function groupBy(array $partners, callable $key): array
    {
        $groups = [];
        foreach ($partners as $partner) {
            $k = $key($partner);
            if ($k !== '') {
                $groups[$k][] = $partner;
            }
        }

        return $groups;
    }
}
