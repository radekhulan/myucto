<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxBonus;

/**
 * Syrové měsíční úhrny za firmu, ze kterých se sestavuje žádost podle
 * § 35d odst. 5 nebo odst. 9 ZDP.
 *
 * Všechny částky jsou v haléřích a pocházejí VÝHRADNĚ ze zmrazených zákonných
 * výsledků aktuálně schválených revizí (`payroll_statutory_results`), tedy
 * z týchž čísel, ze kterých {@see \MyInvoice\Service\Payroll\Payment\PayrollIncomeTaxLiabilityMaterializer}
 * staví odvod záloh. Žádný druhý výpočet tu nevzniká.
 *
 * Proč tři čísla a ne jedno: materializer i kontrolní součty sčítají měsíční
 * bonus (§ 35d odst. 5) a doplatek z ročního zúčtování (§ 35d odst. 9) do
 * JEDNOHO offsetu, protože pro odvod záloh je rozdíl nepodstatný — obojí ho
 * snižuje. Pro žádosti je ale rozdíl zásadní: každé ustanovení má vlastní
 * tiskopis (DPZMB1 vs. DPZDB1) a vlastní období. Rozdělení je jediné, co tahle
 * vrstva přidává.
 */
final readonly class TaxBonusClaimBasis
{
    /**
     * @param string $periodStart První den mzdového období (`payroll_runs.period_start`).
     * @param ?string $paymentDate Den výplaty mzdy za období — datum výplaty bonusu
     *        (`d_bonus` v tiskopisu). Null, když ho žádný běh období nenese.
     * @param int $advanceTaxMinor Úhrn záloh na daň po slevách PŘED snížením o bonusy
     *        (`income_tax` root `advance_tax_minor_units`).
     * @param int $monthlyBonusMinor Úhrn vyplacených měsíčních daňových bonusů
     *        (`income_tax` root `tax_bonus_minor_units`) — § 35d odst. 5.
     * @param int $annualSettlementMinor Úhrn doplatků z ročního zúčtování
     *        (suma `net_pay` osobních `annual_settlement_minor_units`) — § 35d odst. 9.
     * @param list<int> $revisionIds Revize, ze kterých úhrny pocházejí.
     */
    public function __construct(
        public string $periodStart,
        public ?string $paymentDate,
        public int $advanceTaxMinor,
        public int $monthlyBonusMinor,
        public int $annualSettlementMinor,
        public array $revisionIds,
    ) {
        if ($advanceTaxMinor < 0 || $monthlyBonusMinor < 0 || $annualSettlementMinor < 0) {
            throw new \DomainException(
                'Měsíční úhrny pro žádost o daňový bonus nesmí být záporné.',
            );
        }
    }

    /**
     * Nevyužitý převis bonusů nad zálohami — částka, kterou plátce vyplatil
     * z vlastních prostředků a o kterou žádá správce daně.
     *
     * Záměrně TOTOŽNÝ vzorec jako `advance_tax_offset_unapplied_minor`
     * v {@see \MyInvoice\Service\Payroll\Payment\PayrollIncomeTaxLiabilityMaterializer}.
     * Součet vlastních prostředků obou žádostí se mu musí rovnat na haléř —
     * jinak by firma žádala o víc (nebo o míň), než kolik jí systém vykázal
     * jako pohledávku za správcem daně.
     */
    public function unappliedOffsetMinor(): int
    {
        return max(
            0,
            $this->monthlyBonusMinor + $this->annualSettlementMinor - $this->advanceTaxMinor,
        );
    }
}
