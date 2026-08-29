<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

/**
 * Jeden kalendářní měsíc vykazovaného období — syrové úhrny v haléřích.
 *
 * Pokrývá OBĚ vyúčtování najednou, protože pocházejí z jednoho a téhož
 * zmrazeného výsledku: `income_tax` root nese zálohovou daň, měsíční bonus
 * i srážkovou daň v jednom snapshotu. Rozdělit to na dvě čtení by znamenalo
 * dvě pravdy o jednom měsíci.
 */
final readonly class TaxStatementMonth
{
    /**
     * @param int $month 1–12.
     * @param int $headcount Počet osob ve schválených revizích měsíce
     *        (`poc_zamN` v části II tiskopisu DPZVD6).
     * @param int $advanceTaxMinor Úhrn záloh na daň po měsíčních slevách, které
     *        MĚLY být sraženy — před snížením o vyplacené bonusy (sl. 1).
     * @param int $monthlyBonusMinor Vyplacené měsíční daňové bonusy (§ 35d odst. 4).
     * @param int $withholdingTaxMinor Daň vybíraná srážkou podle zvláštní sazby.
     * @param int $annualOverpaymentMinor Přeplatek na dani z ročního zúčtování za
     *        PŘEDCHOZÍ období, vrácený zaměstnancům v tomto měsíci (§ 38ch odst. 5).
     * @param int $annualBonusTopUpMinor Doplatek na daňovém bonusu z ročního
     *        zúčtování, vyplacený v tomto měsíci (§ 35d odst. 8).
     * @param int $remittedAdvanceMinor Skutečně odvedeno na zálohové dani.
     * @param int $remittedWithholdingMinor Skutečně odvedeno na srážkové dani.
     * @param bool $hasApprovedRun Měl měsíc vůbec schválený mzdový běh? Měsíc bez
     *        běhu není měsíc s nulami — do tiskopisu prostě nepatří žádný řádek.
     */
    public function __construct(
        public int $month,
        public int $headcount,
        public int $advanceTaxMinor,
        public int $monthlyBonusMinor,
        public int $withholdingTaxMinor,
        public int $annualOverpaymentMinor,
        public int $annualBonusTopUpMinor,
        public int $remittedAdvanceMinor,
        public int $remittedWithholdingMinor,
        public bool $hasApprovedRun,
    ) {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Měsíc vyúčtování musí být 1 až 12.');
        }
        foreach ([
            'počet zaměstnanců' => $headcount,
            'úhrn záloh na daň' => $advanceTaxMinor,
            'úhrn měsíčních bonusů' => $monthlyBonusMinor,
            'úhrn srážkové daně' => $withholdingTaxMinor,
            'přeplatek z ročního zúčtování' => $annualOverpaymentMinor,
            'doplatek na daňovém bonusu' => $annualBonusTopUpMinor,
        ] as $label => $value) {
            if ($value < 0) {
                throw new \DomainException(ucfirst($label) . ' nesmí být záporný.');
            }
        }
        // Odvod smí být záporný jen jako stornovaný zápočet; tiskopis ale
        // u sl. 11 (resp. sl. 10 u DPSVD2) zápornou hodnotu ZAKAZUJE kritickou
        // kontrolou, takže se to musí zastavit tady, ne až u podatelny.
        if ($remittedAdvanceMinor < 0 || $remittedWithholdingMinor < 0) {
            throw new \DomainException(
                'Úhrn skutečně odvedené daně vyšel záporně — zkontrolujte '
                . 'spárování plateb finančnímu úřadu.',
            );
        }
    }

    /** Doplatek na bonusu je součástí téhož sloupce jako měsíční bonusy. */
    public function bonusColumnMinor(): int
    {
        return $this->monthlyBonusMinor + $this->annualBonusTopUpMinor;
    }
}
