<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

/**
 * Vyúčtování daně z příjmů ze závislé činnosti (§ 38j odst. 4 ZDP), písemnost
 * DPZVD6, tiskopis 25 5459.
 *
 * Drží to, co se z mezd dá odvodit: část I. po měsících, její úhrn (ř. 13),
 * počty zaměstnanců pro část II., přílohu č. 1 (místa výkonu práce) a přehled
 * vrácených přeplatků z ročního zúčtování.
 *
 * ## Co ZÁMĚRNĚ neobsahuje
 *
 * - **Sl. 3 (předepsáno k přímé úhradě)** — platební výměr správce daně.
 *   Aplikace ho neeviduje a odhadnout ho nelze; nula je tu správná hodnota
 *   pro plátce, kterému správce daně nic nepředepsal, a to je většina.
 * - **Příloha č. 3 a č. 4 (opravy podle § 38i)** — modul opravy neeviduje
 *   jako samostatný záznam „měsíc chybný / měsíc opravy / částka". Opravuje
 *   se přepočtem revize, což je jiná operace než dodatečná srážka podle § 38i.
 *   Prázdná příloha je pravdivá, vymyšlená by nebyla.
 * - **Příloha č. 2 (nerezidenti)** — vyžaduje číslo dokladu totožnosti, jeho
 *   typ a typ zahraničního daňového identifikátoru. Nic z toho aplikace
 *   neeviduje (mzdový list nerezidenta se z téhož důvodu odmítá sestavit).
 *   Místo poloprázdné přílohy proto vzniká varování — přílohu doplní účetní
 *   v EPO, kde ji nelze přehlédnout.
 */
final readonly class DependentActivityStatement
{
    /** Typ vyúčtování (`vdadpz_typ`). */
    public const TYP_RADNE = 'B';
    public const TYP_RADNE_OPRAVNE = 'O';
    public const TYP_DODATECNE = 'D';
    public const TYP_DODATECNE_OPRAVNE = 'E';

    public const TYPY = [
        self::TYP_RADNE,
        self::TYP_RADNE_OPRAVNE,
        self::TYP_DODATECNE,
        self::TYP_DODATECNE_OPRAVNE,
    ];

    /**
     * @param array<int,DependentActivityRow> $months Klíč = číslo měsíce; jen měsíce
     *        se schváleným mzdovým během. Měsíc bez běhu není měsíc s nulami.
     * @param array<int,int> $headcounts Klíč = číslo měsíce, hodnota = počet
     *        zaměstnanců (`poc_zamN`).
     * @param list<WorkplaceHeadcount> $workplaces Příloha č. 1 k 1. prosinci.
     * @param list<array{month:int,amount:int}> $overpaymentPayouts Časové rozlišení
     *        vrácených přeplatků z ročního zúčtování (`VetaG`), v celých korunách.
     * @param int $annualOverpaymentTotal `uhrnprepl` — úhrn přeplatků z ročního
     *        zúčtování za nejbližší předchozí období, BEZ doplatku na bonusu.
     * @param int $annualBonusTopUpTotal `uhrndopl` — úhrn doplatků na daňovém bonusu
     *        z ročního zúčtování za nejbližší předchozí období.
     * @param list<string> $warnings
     */
    public function __construct(
        public int $year,
        public string $variant,
        public array $months,
        public array $headcounts,
        public array $workplaces,
        public array $overpaymentPayouts,
        public int $annualOverpaymentTotal,
        public int $annualBonusTopUpTotal,
        public int $nonResidentCount,
        public array $warnings = [],
    ) {
        if (!in_array($variant, self::TYPY, true)) {
            throw new \InvalidArgumentException(
                'Typ vyúčtování musí být B (řádné), O (řádné-opravné), '
                . 'D (dodatečné) nebo E (dodatečné-opravné).',
            );
        }
        if ($year < 2010 || $year > 2199) {
            throw new \InvalidArgumentException(
                'Vyúčtování lze podat nejdříve za zdaňovací období 2010.',
            );
        }
        foreach (array_keys($months) as $month) {
            if ($month < 1 || $month > 12) {
                throw new \LogicException('Vyúčtování má řádek mimo kalendářní rok.');
            }
        }
    }

    /** ÚHRN (ř. 13) — po sloupcích součet měsíčních řádků. */
    public function total(): DependentActivityRow
    {
        $total = DependentActivityRow::zero();
        foreach ($this->months as $row) {
            $total = $total->plus($row);
        }

        return $total;
    }

    /** @return array<string,mixed> */
    public function toSummary(): array
    {
        $months = [];
        foreach ($this->months as $month => $row) {
            $months[] = ['month' => $month, 'headcount' => $this->headcounts[$month] ?? 0]
                + $row->toSummary();
        }

        return [
            'form_code' => 'dpzvd6',
            'year' => $this->year,
            'variant' => $this->variant,
            'months' => $months,
            'total' => $this->total()->toSummary(),
            'annual_overpayment_total' => $this->annualOverpaymentTotal,
            'annual_bonus_top_up_total' => $this->annualBonusTopUpTotal,
            'overpayment_payouts' => $this->overpaymentPayouts,
            'workplaces' => array_map(
                static fn (WorkplaceHeadcount $place): array => [
                    'municipality_code' => $place->municipalityCode,
                    'municipality_name' => $place->municipalityName,
                    'district_name' => $place->districtName,
                    'headcount' => $place->headcount,
                ],
                $this->workplaces,
            ),
            'non_resident_count' => $this->nonResidentCount,
            'warnings' => $this->warnings,
        ];
    }
}
