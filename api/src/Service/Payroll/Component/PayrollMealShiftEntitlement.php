<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/**
 * Kolik osvobozených příspěvků na stravování zaměstnanci za měsíc VZNIKLO.
 *
 * § 6 odst. 9 písm. b) ZDP váže limit na JEDNU SMĚNU, ne na měsíc. Mzdový vstup
 * je přitom měsíční, takže se měsíční strop skládá jako
 * `počet nároků × limit na jednu směnu` — a počet nároků musí být DOLOŽENÝ,
 * ne odhadnutý z úvazku nebo z počtu pracovních dnů.
 *
 * Jeden nárok vzniká za každou směnu, ve které zaměstnanec odpracoval alespoň
 * zákonné minimum a nevznikl mu nárok na stravné v rámci cestovních náhrad.
 * DRUHÝ nárok téže směny vzniká jen tehdy, je-li směna v úhrnu s povinnou
 * přestávkou delší než 11 hodin. `count` je součet obojího.
 *
 * `basis` říká, kterou větev zákona případ použil:
 *
 *  - `shift` — výkon práce je rozvržen na směny, jednotkou je směna,
 *  - `calendar_day` — není rozvržen na směny (v datech: za období nejsou
 *    publikované žádné směny), jednotkou je kalendářní den. Tuhle větev zákon
 *    výslovně upravuje a podmínku druhého příspěvku v ní staví jinak: „pokud
 *    během tohoto dne zaměstnanec vykonával práci alespoň 11 hodin", tedy
 *    NEOSTŘE a o odpracované době, ne o délce intervalu.
 *  - `mixed` — osoba má souběžné vztahy v obou režimech; každý vztah se
 *    vyhodnotil vlastní větví a teprve výsledné počty se sečetly.
 *
 * `complete` je fail-closed brána: `false` znamená, že docházka období není
 * uzavřená, takže počet nároků NENÍ podklad, jen mezistav. `missing` pak řekne,
 * co konkrétně chybí — bez toho by uživatel viděl jen „nelze schválit".
 */
final readonly class PayrollMealShiftEntitlement implements \JsonSerializable
{
    public const BASIS_SHIFT = 'shift';

    public const BASIS_CALENDAR_DAY = 'calendar_day';

    public const BASIS_MIXED = 'mixed';

    /**
     * @param list<string> $missing Kódy chybějícího podkladu, viz
     *        {@see PayrollMealShiftEvidenceService::MISSING_*}.
     */
    public function __construct(
        public string $periodStart,
        public string $basis,
        public int $qualifyingCount,
        public int $secondContributionCount,
        public bool $complete,
        public array $missing = [],
    ) {
        if (!in_array(
            $basis,
            [self::BASIS_SHIFT, self::BASIS_CALENDAR_DAY, self::BASIS_MIXED],
            true,
        )) {
            throw new \InvalidArgumentException('Neznámá větev nároku na stravování.');
        }
        if ($qualifyingCount < 0 || $secondContributionCount < 0) {
            throw new \InvalidArgumentException('Počet nároků nesmí být záporný.');
        }
        if ($secondContributionCount > $qualifyingCount) {
            throw new \InvalidArgumentException(
                'Druhý příspěvek nemůže vzniknout bez prvního ve stejné směně.',
            );
        }
    }

    public function count(): int
    {
        return $this->qualifyingCount + $this->secondContributionCount;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'period_start' => $this->periodStart,
            'basis' => $this->basis,
            'qualifying_count' => $this->qualifyingCount,
            'second_contribution_count' => $this->secondContributionCount,
            'count' => $this->count(),
            'complete' => $this->complete,
            'missing' => $this->missing,
        ];
    }
}
