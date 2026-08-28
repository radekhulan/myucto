<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use JsonSerializable;

/**
 * Výsledek ročního zúčtování — nebo doložené odmítnutí.
 *
 * Jeden typ pro obojí schválně: „neprovedeno" je taky výsledek posouzení
 * podmínek § 38ch a musí být stejně dohledatelné jako spočítaná částka. Kdyby
 * odmítnutí bylo jen výjimkou, nikde by nezůstalo, PROČ se zúčtování neprovedlo,
 * a příští rok by se ta otázka řešila znovu od nuly.
 *
 * Rozdíl na dani a rozdíl na bonusu se drží ODDĚLENĚ, i když se nakonec sčítají.
 * Vyžaduje to § 35d odst. 7 (počítají se každý zvlášť) a jednotné měsíční
 * hlášení, které je vykazuje samostatnými položkami 10322 a 10323.
 */
final readonly class AnnualSettlementResult implements JsonSerializable
{
    public const SCHEMA_VERSION = 'payroll-annual-settlement.v1';

    /**
     * @param list<AnnualSettlementBlocker> $blockers
     * @param array<string,mixed> $trace
     */
    private function __construct(
        public int $taxYear,
        public bool $performed,
        public array $blockers,
        public ?AnnualSettlementOutcome $outcome,
        /** § 16 odst. 2: základ daně zaokrouhlený na celá sta Kč dolů. */
        public int $roundedTaxBaseMinorUnits,
        /** § 16 odst. 1: daň před slevami. */
        public int $taxBeforeCreditsMinorUnits,
        /** § 35ba: roční slevy pro poplatníky daně z příjmů fyzických osob. */
        public int $annualCreditsMinorUnits,
        /** Uplatněná část těch slev — nikdy víc než daň. */
        public int $appliedCreditsMinorUnits,
        /** § 35c odst. 1: roční nárok na daňové zvýhodnění. */
        public int $childEntitlementMinorUnits,
        /** § 35c odst. 2: část uplatněná jako sleva na dani. */
        public int $childCreditMinorUnits,
        /** § 35c odst. 3: část náležející jako daňový bonus. */
        public int $annualTaxBonusMinorUnits,
        /** Daň po slevách i po § 35c — proti té se porovnávají zálohy. */
        public int $taxAfterAllCreditsMinorUnits,
        /** § 35d odst. 7: rozdíl na dani. Kladný = přeplatek. */
        public int $taxDifferenceMinorUnits,
        /** § 35d odst. 7: rozdíl na daňovém bonusu. Kladný = doplatek na bonusu. */
        public int $bonusDifferenceMinorUnits,
        /** Součet obou rozdílů — „doplatek ze zúčtování" podle § 35d odst. 8. */
        public int $settlementDifferenceMinorUnits,
        /** Kolik se skutečně vyplatí (0, není-li co nebo je-li to do 50 Kč). */
        public int $payableMinorUnits,
        public bool $annualBonusThresholdMet,
        public array $trace,
        public int $annualTaxBonusCandidateMinorUnits,
        public bool $annualBonusAmountThresholdMet,
        public string $annualBonusEligibilityReason,
    ) {}

    /**
     * @param list<AnnualSettlementBlocker> $blockers
     * @param array<string,mixed> $trace
     */
    public static function refused(
        int $taxYear,
        array $blockers,
        array $trace = [],
    ): self {
        if ($blockers === []) {
            throw new \InvalidArgumentException(
                'Odmítnuté roční zúčtování musí uvést důvod.',
            );
        }

        return new self(
            $taxYear,
            false,
            $blockers,
            null,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            false,
            $trace,
            0,
            false,
            'income_below_threshold',
        );
    }

    /** @param array<string,mixed> $trace */
    public static function performed(
        int $taxYear,
        AnnualSettlementOutcome $outcome,
        int $roundedTaxBaseMinorUnits,
        int $taxBeforeCreditsMinorUnits,
        int $annualCreditsMinorUnits,
        int $appliedCreditsMinorUnits,
        int $childEntitlementMinorUnits,
        int $childCreditMinorUnits,
        int $annualTaxBonusMinorUnits,
        int $taxAfterAllCreditsMinorUnits,
        int $taxDifferenceMinorUnits,
        int $bonusDifferenceMinorUnits,
        int $settlementDifferenceMinorUnits,
        int $payableMinorUnits,
        bool $annualBonusThresholdMet,
        array $trace,
        ?int $annualTaxBonusCandidateMinorUnits = null,
        ?bool $annualBonusAmountThresholdMet = null,
        ?string $annualBonusEligibilityReason = null,
    ): self {
        $candidate = $annualTaxBonusCandidateMinorUnits ?? $annualTaxBonusMinorUnits;
        $amountThresholdMet = $annualBonusAmountThresholdMet
            ?? AnnualSettlementStatute::isAnnualBonusAmountEligible($candidate);
        $eligibilityReason = $annualBonusEligibilityReason ?? match (true) {
            !$annualBonusThresholdMet => 'income_below_threshold',
            !$amountThresholdMet => 'amount_below_threshold',
            default => 'eligible',
        };

        return new self(
            $taxYear,
            true,
            [],
            $outcome,
            $roundedTaxBaseMinorUnits,
            $taxBeforeCreditsMinorUnits,
            $annualCreditsMinorUnits,
            $appliedCreditsMinorUnits,
            $childEntitlementMinorUnits,
            $childCreditMinorUnits,
            $annualTaxBonusMinorUnits,
            $taxAfterAllCreditsMinorUnits,
            $taxDifferenceMinorUnits,
            $bonusDifferenceMinorUnits,
            $settlementDifferenceMinorUnits,
            $payableMinorUnits,
            $annualBonusThresholdMet,
            $trace,
            $candidate,
            $amountThresholdMet,
            $eligibilityReason,
        );
    }

    /** @return list<string> */
    public function blockerCodes(): array
    {
        return array_map(
            static fn (AnnualSettlementBlocker $blocker): string => $blocker->value,
            $this->blockers,
        );
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'tax_year' => $this->taxYear,
            'performed' => $this->performed,
            'blockers' => $this->blockerCodes(),
            'outcome' => $this->outcome?->value,
            'rounded_tax_base_minor_units' => $this->roundedTaxBaseMinorUnits,
            'tax_before_credits_minor_units' => $this->taxBeforeCreditsMinorUnits,
            'annual_credits_minor_units' => $this->annualCreditsMinorUnits,
            'applied_credits_minor_units' => $this->appliedCreditsMinorUnits,
            'child_entitlement_minor_units' => $this->childEntitlementMinorUnits,
            'child_credit_minor_units' => $this->childCreditMinorUnits,
            'annual_tax_bonus_minor_units' => $this->annualTaxBonusMinorUnits,
            'tax_after_all_credits_minor_units' => $this->taxAfterAllCreditsMinorUnits,
            'tax_difference_minor_units' => $this->taxDifferenceMinorUnits,
            'bonus_difference_minor_units' => $this->bonusDifferenceMinorUnits,
            'settlement_difference_minor_units' => $this->settlementDifferenceMinorUnits,
            'payable_minor_units' => $this->payableMinorUnits,
            'annual_bonus_threshold_met' => $this->annualBonusThresholdMet,
            'annual_bonus_candidate_minor_units' => $this->annualTaxBonusCandidateMinorUnits,
            'annual_bonus_income_threshold_met' => $this->annualBonusThresholdMet,
            'annual_bonus_amount_threshold_met' => $this->annualBonusAmountThresholdMet,
            'annual_bonus_eligible' => $this->annualBonusThresholdMet
                && $this->annualBonusAmountThresholdMet,
            'annual_bonus_eligibility_reason' => $this->annualBonusEligibilityReason,
            'bonus_qualifying_income_minor_units' =>
                (int) ($this->trace['total_bonus_qualifying_income_minor_units'] ?? 0),
            'bonus_minimum_income_minor_units' =>
                (int) ($this->trace['rates']['bonus_minimum_income_minor_units'] ?? 0),
            'bonus_minimum_amount_minor_units' =>
                (int) ($this->trace['rates']['bonus_minimum_amount_minor_units'] ?? 0),
            'monthly_tax_bonus_minor_units' =>
                (int) ($this->trace['total_monthly_tax_bonus_minor_units'] ?? 0),
            'trace' => $this->trace,
        ];
    }
}
