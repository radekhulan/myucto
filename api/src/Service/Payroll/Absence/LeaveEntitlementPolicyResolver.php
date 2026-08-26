<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

final class LeaveEntitlementPolicyResolver
{
    /**
     * @param list<array<string,mixed>> $terms
     * @param list<array<string,mixed>> $policies
     * @return array{
     *   weekly_minutes:?int,
     *   entitlement_weeks:?int,
     *   allowance_source:?string,
     *   term_ids:list<int>,
     *   policy_ids:list<int>,
     *   blockers:list<string>
     * }
     */
    public function resolve(
        string $periodFrom,
        string $periodTo,
        string $relationType,
        array $terms,
        array $policies,
        int $agreementWeeklyMinutes,
    ): array {
        $from = new \DateTimeImmutable($periodFrom);
        $to = new \DateTimeImmutable($periodTo);
        if ($to < $from) {
            throw new \InvalidArgumentException('Období nároku dovolené není platné.');
        }

        $weeklyValues = [];
        $allowanceValues = [];
        $allowanceSources = [];
        $termIds = [];
        $policyIds = [];
        $blockers = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $term = $this->effective($terms, 'effective_from', 'effective_to', $date);
            if ($term === null) {
                $blockers['employment_terms_missing'] = true;
                continue;
            }
            $termIds[(int) $term['id']] = true;

            if (in_array($relationType, ['dpp', 'dpc'], true)) {
                $weeklyValues[$agreementWeeklyMinutes] = true;
            } else {
                $weekly = $this->weeklyMinutes($term['weekly_hours'] ?? null);
                if ($weekly === null) {
                    $blockers['weekly_working_time_missing'] = true;
                } else {
                    $weeklyValues[$weekly] = true;
                }
            }

            $override = $this->positiveInt($term['leave_entitlement_weeks_override'] ?? null);
            if ($override !== null) {
                $allowanceValues[$override] = true;
                $allowanceSources['employment_override'] = true;
                continue;
            }

            $policy = $this->effective($policies, 'valid_from', 'valid_to', $date);
            if ($policy === null) {
                $blockers['employer_leave_policy_missing'] = true;
                continue;
            }
            $policyIds[(int) $policy['id']] = true;
            $weeks = $this->positiveInt($policy['leave_entitlement_weeks'] ?? null);
            if ($weeks === null) {
                $blockers['employer_leave_policy_missing'] = true;
                continue;
            }
            $allowanceValues[$weeks] = true;
            $allowanceSources['company_policy'] = true;
        }

        if (count($weeklyValues) > 1) {
            $blockers['weekly_working_time_changed'] = true;
        }
        if (count($allowanceValues) > 1) {
            $blockers['leave_allowance_changed'] = true;
        }

        return [
            'weekly_minutes' => count($weeklyValues) === 1
                ? (int) array_key_first($weeklyValues)
                : null,
            'entitlement_weeks' => count($allowanceValues) === 1
                ? (int) array_key_first($allowanceValues)
                : null,
            'allowance_source' => count($allowanceSources) === 1
                ? (string) array_key_first($allowanceSources)
                : (count($allowanceValues) === 1 && $allowanceSources !== [] ? 'mixed_same_value' : null),
            'term_ids' => array_map('intval', array_keys($termIds)),
            'policy_ids' => array_map('intval', array_keys($policyIds)),
            'blockers' => array_keys($blockers),
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed>|null */
    private function effective(
        array $rows,
        string $fromField,
        string $toField,
        string $date,
    ): ?array {
        $found = null;
        foreach ($rows as $row) {
            $from = $row[$fromField] ?? null;
            $to = $row[$toField] ?? null;
            if (!is_string($from) || $from > $date
                || ($to !== null && (!is_string($to) || $to < $date))) {
                continue;
            }
            if ($found !== null) {
                return null;
            }
            $found = $row;
        }

        return $found;
    }

    private function weeklyMinutes(mixed $value): ?int
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', (string) $value, $parts) !== 1
        ) {
            return null;
        }
        $centihours = ((int) $parts[1] * 100)
            + (int) str_pad($parts[2] ?? '', 2, '0');
        $minuteHundredths = $centihours * 60;
        if ($centihours <= 0 || $minuteHundredths % 100 !== 0) {
            return null;
        }

        return intdiv($minuteHundredths, 100);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
