<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

/**
 * Vyúčtování daně vybírané srážkou podle zvláštní sazby daně (§ 38d ZDP),
 * písemnost DPSVD2, tiskopis 25 5466.
 *
 * Není to „DPZ pro srážkovou daň": je to vlastní písemnost s vlastním kódem
 * ULADIS (`DPS`), vlastním schématem a druhem příjmu ve `VetaD`. Mzdový modul
 * sráží daň zvláštní sazbou jen fyzickým osobám (dohody o provedení práce
 * a drobné příjmy bez prohlášení), takže druh příjmu je vždy 772.
 *
 * ## Co ZÁMĚRNĚ neobsahuje
 *
 * - **Příloha (tiskopis 25 5466/A, `VetaB`)** — opravy podle § 38d odst. 8
 *   (dodatečná srážka nebo vrácení přeplatku nesprávně sražené vyšší daně).
 *   Modul opravy neeviduje jako samostatný záznam; opravuje se přepočtem
 *   revize. Prázdná příloha je pravdivá.
 * - **`VetaA` (bankovní účet)** — od zdaňovacího období 2012 se nevyplňuje.
 * - **Sl. 7 (předepsáno k přímé úhradě)** — platební výměr správce daně,
 *   který aplikace neeviduje.
 */
final readonly class WithholdingTaxStatement
{
    /** Druh příjmu (`c_drp`). Mzdová srážková daň je vždy příjem fyzických osob. */
    public const DRUH_PRIJMU_FO = '772';
    public const DRUH_PRIJMU_PO = '771';

    /** Typ vyúčtování (`dapdps_forma`) — tytéž hodnoty jako u DPZVD6. */
    public const TYPY = [
        DependentActivityStatement::TYP_RADNE,
        DependentActivityStatement::TYP_RADNE_OPRAVNE,
        DependentActivityStatement::TYP_DODATECNE,
        DependentActivityStatement::TYP_DODATECNE_OPRAVNE,
    ];

    /**
     * @param array<int,WithholdingTaxRow> $months Klíč = číslo měsíce.
     * @param list<string> $warnings
     */
    public function __construct(
        public int $year,
        public string $variant,
        public string $incomeKind,
        public array $months,
        public array $warnings = [],
    ) {
        if (!in_array($variant, self::TYPY, true)) {
            throw new \InvalidArgumentException(
                'Typ vyúčtování musí být B, O, D nebo E.',
            );
        }
        if (!in_array($incomeKind, [self::DRUH_PRIJMU_FO, self::DRUH_PRIJMU_PO], true)) {
            throw new \InvalidArgumentException(
                'Druh příjmu musí být 771 (právnické osoby) nebo 772 (fyzické osoby).',
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

    /** ÚHRN (ř. 13). */
    public function total(): WithholdingTaxRow
    {
        $total = WithholdingTaxRow::zero();
        foreach ($this->months as $row) {
            $total = $total->plus($row);
        }

        return $total;
    }

    /**
     * Ř. 5 části II. — rozdíl mezi odvedenou daní (ř. 4) a daní, která měla být
     * sražena (ř. 1). Kladná částka = odvedeno víc, záporná = zbývá doplatit.
     */
    public function balanceMinor(): int
    {
        $total = $this->total();

        return $total->remittedMinor - $total->taxDueMinor;
    }

    /** @return array<string,mixed> */
    public function toSummary(): array
    {
        $months = [];
        foreach ($this->months as $month => $row) {
            $months[] = ['month' => $month] + $row->toSummary();
        }

        return [
            'form_code' => 'dpsvd2',
            'year' => $this->year,
            'variant' => $this->variant,
            'income_kind' => $this->incomeKind,
            'months' => $months,
            'total' => $this->total()->toSummary(),
            'balance_minor' => $this->balanceMinor(),
            'warnings' => $this->warnings,
        ];
    }
}
