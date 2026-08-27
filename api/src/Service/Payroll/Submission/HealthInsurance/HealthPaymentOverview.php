<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class HealthPaymentOverview
{
    public const SCHEMA_REFERENCE = 'payroll-health-payment-overview.v1';

    /**
     * @param array{
     *   person_count:int,
     *   assessment_base_minor_units:int,
     *   employee_contribution_minor_units:int,
     *   employer_contribution_minor_units:int,
     *   total_contribution_minor_units:int
     * } $totals
     * @param list<array{
     *   employee_reference:string,
     *   display_name:string,
     *   assessment_base_minor_units:int,
     *   employee_contribution_minor_units:int,
     *   employer_contribution_minor_units:int,
     *   total_contribution_minor_units:int
     * }> $people
     */
    public function __construct(
        public int $supplierId,
        public int $runId,
        public int $revisionId,
        public int $revisionNo,
        public string $revisionKind,
        public string $period,
        public string $insurerCode,
        public int $statutoryResultId,
        public string $statutoryResultHash,
        public string $rulesetId,
        public string $rulesetHash,
        public array $totals,
        public array $people,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_reference' => self::SCHEMA_REFERENCE,
            'document_kind' => 'internal_health_payment_overview',
            'official_submission' => [
                'supported' => false,
                'reason_code' => 'health_insurance_official_format_unavailable',
            ],
            'supplier_id' => $this->supplierId,
            'run_id' => $this->runId,
            'revision_id' => $this->revisionId,
            'revision_no' => $this->revisionNo,
            'revision_kind' => $this->revisionKind,
            'period' => $this->period,
            'currency_code' => 'CZK',
            'insurer' => [
                'code' => $this->insurerCode,
            ],
            'source' => [
                'statutory_result_id' => $this->statutoryResultId,
                'statutory_result_hash' => $this->statutoryResultHash,
                'ruleset_id' => $this->rulesetId,
                'ruleset_hash' => $this->rulesetHash,
            ],
            'totals' => $this->totals,
            'people' => $this->people,
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    public function sha256(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    public function downloadBytes(): string
    {
        return $this->canonicalJson();
    }

    public function filename(): string
    {
        return sprintf(
            'zp-prehled-%s-%s-revize-%d.json',
            $this->period,
            $this->insurerCode,
            $this->revisionId,
        );
    }
}
