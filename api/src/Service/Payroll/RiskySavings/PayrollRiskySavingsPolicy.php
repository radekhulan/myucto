<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\RiskySavings;

final class PayrollRiskySavingsPolicy
{
    public const EFFECTIVE_FROM = '2026-01-01';
    public const MINIMUM_SHIFT_EIGHTHS = 24;
    public const RATE_BASIS_POINTS = 400;

    /**
     * @param array<string,mixed> $evidence
     * @return list<string>
     */
    public function issues(array $evidence, string $periodStart): array
    {
        $issues = [];
        if (($evidence['status'] ?? null) !== 'approved') {
            $issues[] = 'risky_savings_evidence_not_approved';
        }
        if (PayrollRiskySavingsRiskFactor::tryFrom(
            is_string($evidence['risk_factor'] ?? null)
                ? $evidence['risk_factor'] : '',
        ) === null) {
            $issues[] = 'risky_savings_risk_factor_invalid';
        }
        if (($evidence['work_category'] ?? null) !== 3) {
            $issues[] = 'risky_savings_work_category_invalid';
        }
        $eighths = $evidence['qualifying_shift_eighths'] ?? null;
        if (!is_int($eighths) || $eighths < 0) {
            $issues[] = 'risky_savings_shift_eighths_invalid';
        }
        $claimedOn = $evidence['right_claimed_on'] ?? null;
        if (!is_string($claimedOn) || !$this->date($claimedOn)) {
            $issues[] = 'risky_savings_claim_date_invalid';
        }
        foreach (['pension_company', 'product_reference'] as $field) {
            if (!is_string($evidence[$field] ?? null)
                || trim((string) $evidence[$field]) === ''
            ) {
                $issues[] = "risky_savings_{$field}_missing";
            }
        }
        if (!is_int($evidence['institution_account_id'] ?? null)
            || $evidence['institution_account_id'] <= 0
            || !is_int($evidence['institution_account_row_version'] ?? null)
            || $evidence['institution_account_row_version'] <= 0
            || !is_string($evidence['institution_account_hash'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $evidence['institution_account_hash']) !== 1
        ) {
            $issues[] = 'risky_savings_payment_target_invalid';
        } elseif (array_key_exists(
            'current_institution_account_row_version',
            $evidence,
        ) && (
            !is_int($evidence['current_institution_account_row_version'])
            || $evidence['current_institution_account_row_version']
                !== $evidence['institution_account_row_version']
            || !is_string($evidence['current_institution_account_hash'] ?? null)
            || !hash_equals(
                $evidence['institution_account_hash'],
                $evidence['current_institution_account_hash'],
            )
        )) {
            $issues[] = 'risky_savings_payment_target_changed';
        }
        return $issues;
    }

    /**
     * § 5 je samostatná povinnost zaměstnavatele, nikoli podmínka vzniku 4 %.
     *
     * @param array<string,mixed> $evidence
     * @return list<string>
     */
    public function warnings(array $evidence, string $periodStart): array
    {
        $informedOn = $evidence['employee_informed_on'] ?? null;
        if (!is_string($informedOn) || !$this->date($informedOn)) {
            return ['risky_savings_employee_not_informed'];
        }
        return [];
    }

    /** @param array<string,mixed> $evidence */
    public function obligationArises(array $evidence, string $periodStart): bool
    {
        return $this->issues($evidence, $periodStart) === []
            && $periodStart >= self::EFFECTIVE_FROM
            && $evidence['right_claimed_on'] < $periodStart
            && $evidence['qualifying_shift_eighths'] >= self::MINIMUM_SHIFT_EIGHTHS;
    }

    public function dueOn(string $periodStart): string
    {
        $period = new \DateTimeImmutable($periodStart);
        return $period->modify('last day of next month')->format('Y-m-d');
    }

    private function date(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
