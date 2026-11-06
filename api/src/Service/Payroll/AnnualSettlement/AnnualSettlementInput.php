<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\IncomeTax\ExternalEmployerTaxCertificate;

/**
 * Všechno, z čeho se roční zúčtování počítá — a nic navíc.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Roční částky se NEPOČÍTAJÍ PODRUHÉ
 * ─────────────────────────────────────────────────────────────────────────────
 * `advanceBase…` až `bonusQualifyingIncome…` nejsou nové součty nad mzdovými
 * listy. Jsou to hodnoty z `payroll_statutory_accumulator_entries`, které se
 * ukládají při schválení běhu (`storeApprovedStatutoryAccumulators`) a jsou
 * přírůstkové a neměnné. Roční zúčtování je jenom sečte — nesahá na jediný
 * měsíční výsledek a žádný z nich nepřepočítává. Kdyby si vlastní součet dělalo,
 * vznikla by druhá pravda a rozdíl by se projevil až u zaměstnance na výplatní
 * pásce.
 *
 * `completedMonths` je počet měsíců, v nichž byla u zaměstnance vypočtena záloha
 * na daň. Nula znamená, že v roce není co zúčtovávat.
 */
final readonly class AnnualSettlementInput
{
    /**
     * @param list<AnnualSettlementCreditMonths> $creditMonths
     * @param list<AnnualSettlementChildMonths> $childMonths
     * @param list<ExternalEmployerTaxCertificate> $externalCertificates
     */
    public function __construct(
        public int $taxYear,
        public int $completedMonths,
        /** § 38h odst. 1: úhrn nezaokrouhlených základů pro výpočet zálohy. */
        public int $advanceBaseMinorUnits,
        /** Úhrn skutečně sražených záloh po slevách (§ 35d odst. 7). */
        public int $advanceTaxMinorUnits,
        /** Úhrn měsíčně poskytnutých slev podle § 35ba — jen pro doložení, ne vstup výpočtu. */
        public int $appliedNonRefundableCreditsMinorUnits,
        /** Úhrn měsíčně poskytnutých slev podle § 35c — jen pro doložení. */
        public int $appliedChildCreditMinorUnits,
        /** Úhrn vyplacených MĚSÍČNÍCH daňových bonusů (§ 35d odst. 4). */
        public int $monthlyTaxBonusMinorUnits,
        /** Příjmy rozhodné pro roční nárok na bonus (§ 35c odst. 4). */
        public int $bonusQualifyingIncomeMinorUnits,
        /**
         * § 6 odst. 4: samostatný základ od tohoto plátce. Do roku 2026 základ
         * srážkové daně, který do ročního zúčtování NEVSTUPUJE; od roku 2027
         * základ zálohy, který do úhrnu mezd vstupuje (§ 38ch odst. 4).
         */
        public int $withholdingBaseMinorUnits,
        public int $withholdingTaxMinorUnits,
        public array $creditMonths,
        public array $childMonths,
        public array $externalCertificates,
        /**
         * § 38h odst. 6: položky uplatňované až ročně — § 15 a § 35 odst. 4.
         *
         * @var list<AnnualNonTaxableClaim>
         */
        public array $nonTaxableClaims = [],
        /** § 35ba odst. 1 písm. b), § 35bb — sleva na manžela. */
        public ?AnnualSpouseCreditClaim $spouseClaim = null,
        /**
         * § 35ba odst. 1 písm. g), § 35bd — sleva za umístění dítěte, jeden
         * nárok na dítě.
         *
         * @var list<AnnualPreschoolCreditClaim>
         */
        public array $preschoolClaims = [],
    ) {
        if ($taxYear < 2000 || $taxYear > 2199) {
            throw new \InvalidArgumentException('Rok ročního zúčtování není platný.');
        }
        if ($completedMonths < 0 || $completedMonths > AnnualTaxRates::MONTHS_IN_YEAR) {
            throw new \InvalidArgumentException(
                'Počet uzavřených měsíců ročního zúčtování není platný.',
            );
        }
        foreach ([
            'advanceBaseMinorUnits' => $advanceBaseMinorUnits,
            'advanceTaxMinorUnits' => $advanceTaxMinorUnits,
            'appliedNonRefundableCreditsMinorUnits' => $appliedNonRefundableCreditsMinorUnits,
            'appliedChildCreditMinorUnits' => $appliedChildCreditMinorUnits,
            'monthlyTaxBonusMinorUnits' => $monthlyTaxBonusMinorUnits,
            'bonusQualifyingIncomeMinorUnits' => $bonusQualifyingIncomeMinorUnits,
            'withholdingBaseMinorUnits' => $withholdingBaseMinorUnits,
            'withholdingTaxMinorUnits' => $withholdingTaxMinorUnits,
        ] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(
                    "Roční kumulace {$name} nesmí být záporná.",
                );
            }
        }
        self::assertList($creditMonths, AnnualSettlementCreditMonths::class);
        self::assertList($childMonths, AnnualSettlementChildMonths::class);
        self::assertList($externalCertificates, ExternalEmployerTaxCertificate::class);
        self::assertList($nonTaxableClaims, AnnualNonTaxableClaim::class);
        self::assertList($preschoolClaims, AnnualPreschoolCreditClaim::class);
        $seenPreschool = [];
        foreach ($preschoolClaims as $claim) {
            if (isset($seenPreschool[$claim->childReference])) {
                throw new \InvalidArgumentException(
                    'Sleva za umístění téhož dítěte nesmí být v ročním zúčtování dvakrát.',
                );
            }
            $seenPreschool[$claim->childReference] = true;
        }

        $seenCredits = [];
        foreach ($creditMonths as $credit) {
            if (isset($seenCredits[$credit->kind->value])) {
                throw new \InvalidArgumentException(
                    'Táž sleva nesmí být v ročním zúčtování dvakrát.',
                );
            }
            $seenCredits[$credit->kind->value] = true;
        }
        $seenChildren = [];
        foreach ($childMonths as $child) {
            if (isset($seenChildren[$child->childReference])) {
                throw new \InvalidArgumentException(
                    'Totéž dítě nesmí být v ročním zúčtování dvakrát.',
                );
            }
            $seenChildren[$child->childReference] = true;
        }
    }

    /**
     * Potvrzení, která se smějí vzít do úhrnu podle § 38ch odst. 4.
     *
     * Doložená (§ 38ch odst. 4 mluví o úhrnu mezd od všech plátců — nedoložené
     * potvrzení není doklad) a úplná (§ 38ch odst. 3 vyjmenovává, co doklad musí
     * nést). Neúplné se do úhrnu NEPŘIČÍTÁ ani částečně — z toho by vyšel
     * přeplatek, který poplatníkovi nenáleží. Místo toho padne překážka.
     *
     * @return list<ExternalEmployerTaxCertificate>
     */
    public function usableExternalCertificates(): array
    {
        return array_values(array_filter(
            $this->externalCertificates,
            static fn (ExternalEmployerTaxCertificate $certificate): bool
                => $certificate->isVerified() && $certificate->isComplete(),
        ));
    }

    /**
     * @param list<object> $values
     * @param class-string $expected
     */
    private static function assertList(array $values, string $expected): void
    {
        foreach ($values as $value) {
            if (!$value instanceof $expected) {
                throw new \InvalidArgumentException(
                    'Vstup ročního zúčtování má neplatnou položku.',
                );
            }
        }
    }
}
