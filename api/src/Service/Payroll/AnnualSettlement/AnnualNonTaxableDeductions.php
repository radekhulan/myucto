<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Výsledek {@see AnnualNonTaxableDeductionCalculator}: co se uplatnilo a proč.
 *
 * `*Claimed` je to, co poplatník uplatňuje, bez `Claimed` je to, co se po
 * limitech a po omezení výší základu skutečně odečte.
 */
final readonly class AnnualNonTaxableDeductions
{
    /** @param array<string,int> $claimedByKind */
    public function __construct(
        public int $donationClaimedMinorUnits,
        public bool $donationThresholdMet,
        public int $donationCapMinorUnits,
        public int $donationMinorUnits,
        public int $interestClaimedMinorUnits,
        public int $interestMonths,
        public int $interestCapMinorUnits,
        public int $interestMinorUnits,
        public int $retirementClaimedMinorUnits,
        public int $retirementMinorUnits,
        /** § 35 odst. 4 — uplatňovaná sleva; do výše daně ji krátí až výpočet zúčtování. */
        public int $enforcementCreditClaimedMinorUnits,
        public array $claimedByKind,
    ) {}

    public static function none(): self
    {
        return new self(0, false, 0, 0, 0, 0, 0, 0, 0, 0, 0, []);
    }

    public function totalMinorUnits(): int
    {
        return $this->donationMinorUnits + $this->interestMinorUnits + $this->retirementMinorUnits;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'statute' => AnnualNonTaxableStatute::toArray(),
            'claimed_by_kind' => $this->claimedByKind,
            'donation' => [
                'claimed_minor_units' => $this->donationClaimedMinorUnits,
                'threshold_met' => $this->donationThresholdMet,
                'cap_minor_units' => $this->donationCapMinorUnits,
                'deducted_minor_units' => $this->donationMinorUnits,
            ],
            'housing_interest' => [
                'claimed_minor_units' => $this->interestClaimedMinorUnits,
                'months' => $this->interestMonths,
                'cap_minor_units' => $this->interestCapMinorUnits,
                'deducted_minor_units' => $this->interestMinorUnits,
            ],
            'retirement_and_care' => [
                'claimed_minor_units' => $this->retirementClaimedMinorUnits,
                'cap_minor_units' => AnnualNonTaxableStatute::RETIREMENT_AND_CARE_LIMIT_MINOR_UNITS,
                'deducted_minor_units' => $this->retirementMinorUnits,
            ],
            'total_deducted_minor_units' => $this->totalMinorUnits(),
        ];
    }
}
