<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxResult;

final readonly class MonthlyEmploymentIncomeTaxResult implements JsonSerializable
{
    /**
     * @param list<RelationshipTaxResult> $relationships
     * @param list<WithholdingTaxGroupResult> $withholdingGroups
     * @param array<string,int> $claimedNonRefundableCreditBreakdown nárokovaná
     *        částka po druzích slevy; JMHZ ji vykazuje samostatně (10299-10302)
     * @param list<string> $issues
     */
    public function __construct(
        public TaxCalculationStatus $status,
        public string $calculationDate,
        public string $employeeReference,
        public string $payerReference,
        public array $relationships,
        public ?MonthlyAdvanceTaxResult $advanceTax,
        public array $withholdingGroups,
        public int $withholdingBaseMinorUnits,
        public int $withholdingTaxMinorUnits,
        public int $claimedNonRefundableCreditsMinorUnits,
        public int $appliedNonRefundableCreditsMinorUnits,
        public array $claimedNonRefundableCreditBreakdown,
        public int $claimedChildCreditMinorUnits,
        public int $appliedChildCreditMinorUnits,
        public AnnualTaxAccumulatorResult $annualAccumulator,
        public array $issues,
        public string $policyId,
        public string $policyHash,
        public string $rulesetId,
        public string $rulesetHash,
        /**
         * Daňová rezidence, ze které výpočet vyšel. Hlášení JMHZ podle ní
         * vynechává atributy, které kontrola 243 nerezidentovi s prohlášením
         * zakazuje. `null` = výsledek z doby před jejím zmrazením.
         */
        public ?TaxResidence $taxResidence = null,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        $residence = $this->taxResidence === null ? [] : ['tax_residence' => $this->taxResidence->value];

        return [
            'status' => $this->status->value,
            'calculation_date' => $this->calculationDate,
            'employee_reference' => $this->employeeReference,
            'payer_reference' => $this->payerReference,
            'relationships' => array_map(
                static fn (RelationshipTaxResult $result): array => $result->jsonSerialize(),
                $this->relationships,
            ),
            'advance_tax' => $this->advanceTax?->jsonSerialize(),
            'withholding_groups' => array_map(
                static fn (WithholdingTaxGroupResult $result): array => $result->jsonSerialize(),
                $this->withholdingGroups,
            ),
            'withholding_base_minor_units' => $this->withholdingBaseMinorUnits,
            'withholding_tax_minor_units' => $this->withholdingTaxMinorUnits,
            'claimed_non_refundable_credits_minor_units' => $this->claimedNonRefundableCreditsMinorUnits,
            'applied_non_refundable_credits_minor_units' => $this->appliedNonRefundableCreditsMinorUnits,
            'claimed_non_refundable_credit_breakdown' => $this->claimedNonRefundableCreditBreakdown,
            'claimed_child_credit_minor_units' => $this->claimedChildCreditMinorUnits,
            'applied_child_credit_minor_units' => $this->appliedChildCreditMinorUnits,
            'annual_accumulator' => $this->annualAccumulator->jsonSerialize(),
            'issues' => $this->issues,
            'policy_id' => $this->policyId,
            'policy_hash' => $this->policyHash,
            'ruleset_id' => $this->rulesetId,
            'ruleset_hash' => $this->rulesetHash,
        ] + $residence;
    }
}
