<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class HealthPaymentOverviewBuilder
{
    /**
     * @param array{
     *   revision:array<string,mixed>,
     *   statutory_result:array<string,mixed>
     * } $source
     * @return list<HealthPaymentOverview>
     */
    public function build(int $supplierId, array $source): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být kladné číslo.');
        }
        $revision = $this->object($source['revision'], 'mzdová revize');
        $statutory = $this->object(
            $source['statutory_result'],
            'zdravotní výsledek',
        );
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        $runId = $this->positiveInt($revision['run_id'] ?? null, 'revision.run_id');
        $revisionNo = $this->positiveInt(
            $revision['revision_no'] ?? null,
            'revision.revision_no',
        );
        $revisionKind = $revision['revision_kind'] ?? null;
        if (!is_string($revisionKind)
            || !in_array($revisionKind, ['regular', 'correction'], true)
        ) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_revision_kind_invalid',
                'Přehled lze vytvořit jen z řádné nebo opravné mzdové revize.',
            );
        }
        if (($revision['revision_status'] ?? null) !== 'approved'
            || ($revision['current_revision_no'] ?? null) !== $revisionNo
        ) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_revision_not_approved',
                'Přehled lze vytvořit jen z aktuální schválené mzdové revize.',
            );
        }
        $periodStart = $this->date(
            $revision['period_start'] ?? null,
            'revision.period_start',
        );
        if (!str_ends_with($periodStart, '-01')) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_period_invalid',
                'Mzdové období nezačíná prvním dnem měsíce.',
            );
        }
        $period = substr($periodStart, 0, 7);
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');

        if (($statutory['schema_version'] ?? null) !== 'payroll-health-result.v1'
            || ($statutory['result_status'] ?? null) !== 'calculated'
        ) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_result_not_calculated',
                'Přehled vyžaduje úplný neměnný výsledek zdravotního pojištění.',
            );
        }
        if (($statutory['supplier_id'] ?? null) !== $supplierId
            || ($statutory['revision_id'] ?? null) !== $revisionId
        ) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_source_scope_mismatch',
                'Zdravotní výsledek nepatří zvolené firmě a revizi.',
            );
        }
        $root = $this->object(
            $statutory['result_snapshot'] ?? null,
            'statutory_result.result_snapshot',
        );
        $rootHash = $this->hash(
            $statutory['result_snapshot_hash'] ?? null,
            'statutory_result.result_snapshot_hash',
        );
        $this->assertSnapshotHash($root, $rootHash, 'zdravotního výsledku');
        if (($root['status'] ?? null) !== 'calculated') {
            throw new HealthInsuranceOverviewException(
                'health_insurance_result_not_calculated',
                'Kořenový výsledek zdravotního pojištění není vypočtený.',
            );
        }
        if ($this->date(
            $root['calculation_date'] ?? null,
            'result.calculation_date',
        ) !== $periodEnd) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_period_mismatch',
                'Datum zdravotního výsledku neodpovídá mzdovému období.',
            );
        }

        $peopleByInsurer = $this->peopleByInsurer(
            $this->rows($statutory['people'] ?? null, 'statutory_result.people'),
        );
        $liabilities = $this->liabilities(
            $this->rows(
                $root['insurer_liabilities'] ?? null,
                'result.insurer_liabilities',
            ),
        );
        $this->assertRootTotals($root, $liabilities);

        $statutoryResultId = $this->positiveInt(
            $statutory['id'] ?? null,
            'statutory_result.id',
        );
        $rulesetId = $this->nonEmptyString(
            $statutory['ruleset_id'] ?? null,
            'statutory_result.ruleset_id',
        );
        $rulesetHash = $this->hash(
            $statutory['ruleset_hash'] ?? null,
            'statutory_result.ruleset_hash',
        );
        if (($root['ruleset_id'] ?? null) !== $rulesetId
            || ($root['ruleset_hash'] ?? null) !== $rulesetHash
        ) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_ruleset_mismatch',
                'Ruleset kořenového zdravotního výsledku nesouhlasí.',
            );
        }
        $overviews = [];
        foreach ($liabilities as $reference => $totals) {
            $code = substr($reference, 1);
            $people = $peopleByInsurer[$reference] ?? [];
            $this->assertInsurerTotals($code, $totals, $people);
            $overviews[] = new HealthPaymentOverview(
                $supplierId,
                $runId,
                $revisionId,
                $revisionNo,
                $revisionKind,
                $period,
                $code,
                $statutoryResultId,
                $rootHash,
                $rulesetId,
                $rulesetHash,
                $totals,
                $people,
            );
            unset($peopleByInsurer[$reference]);
        }
        if ($peopleByInsurer !== []) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_totals_mismatch',
                'Výsledek osoby odkazuje na pojišťovnu bez kořenového závazku.',
            );
        }

        return $overviews;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,list<array{
     *   employee_reference:string,
     *   display_name:string,
     *   assessment_base_minor_units:int,
     *   employee_contribution_minor_units:int,
     *   employer_contribution_minor_units:int,
     *   total_contribution_minor_units:int
     * }>>
     */
    private function peopleByInsurer(array $rows): array
    {
        $grouped = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $employeeId = $this->positiveInt(
                $row['employee_id'] ?? null,
                "people.{$index}.employee_id",
            );
            if (isset($seen[$employeeId])) {
                throw new HealthInsuranceOverviewException(
                    'health_insurance_person_duplicate',
                    'Zdravotní výsledek obsahuje osobu vícekrát.',
                );
            }
            $seen[$employeeId] = true;
            if (($row['result_status'] ?? null) !== 'calculated') {
                throw new HealthInsuranceOverviewException(
                    'health_insurance_person_not_calculated',
                    "Zdravotní výsledek osoby employee:{$employeeId} není vypočtený.",
                );
            }
            $input = $this->object(
                $row['input_snapshot'] ?? null,
                "people.{$index}.input_snapshot",
            );
            $result = $this->object(
                $row['result_snapshot'] ?? null,
                "people.{$index}.result_snapshot",
            );
            $this->assertSnapshotHash(
                $input,
                $this->hash(
                    $row['input_snapshot_hash'] ?? null,
                    "people.{$index}.input_snapshot_hash",
                ),
                "vstupu osoby employee:{$employeeId}",
            );
            $this->assertSnapshotHash(
                $result,
                $this->hash(
                    $row['result_snapshot_hash'] ?? null,
                    "people.{$index}.result_snapshot_hash",
                ),
                "výsledku osoby employee:{$employeeId}",
            );
            if (($result['status'] ?? null) !== 'calculated') {
                throw new HealthInsuranceOverviewException(
                    'health_insurance_person_not_calculated',
                    "Zdravotní výsledek osoby employee:{$employeeId} není vypočtený.",
                );
            }
            $ppzCounted = $result['ppz_counted'] ?? null;
            if (!is_bool($ppzCounted)) {
                throw new HealthInsuranceOverviewException(
                    'health_insurance_person_invalid',
                    "Výsledek osoby employee:{$employeeId} nemá příznak PPZ.",
                );
            }
            if (!$ppzCounted) {
                continue;
            }
            $code = $this->insurerCode(
                $result['insurer_code'] ?? null,
                "people.{$index}.insurer_code",
            );
            $employee = $this->object(
                $input['employee'] ?? null,
                "people.{$index}.input_snapshot.employee",
            );
            $displayName = $this->nonEmptyString(
                $employee['full_name'] ?? null,
                "people.{$index}.input_snapshot.employee.full_name",
            );
            $assessmentBase = $this->minor(
                $result['assessment_base_minor_units'] ?? null,
                "people.{$index}.assessment_base_minor_units",
            );
            $employeeContribution = $this->minor(
                $result['employee_contribution_minor_units'] ?? null,
                "people.{$index}.employee_contribution_minor_units",
            );
            $employerContribution = $this->minor(
                $result['employer_contribution_minor_units'] ?? null,
                "people.{$index}.employer_contribution_minor_units",
            );
            $totalContribution = $this->minor(
                $result['total_contribution_minor_units'] ?? null,
                "people.{$index}.total_contribution_minor_units",
            );
            if ($this->add($employeeContribution, $employerContribution)
                !== $totalContribution
            ) {
                throw new HealthInsuranceOverviewException(
                    'health_insurance_totals_mismatch',
                    "Pojistné osoby employee:{$employeeId} nemá shodný součet.",
                );
            }
            $grouped["i{$code}"][$employeeId] = [
                'employee_reference' => "employee:{$employeeId}",
                'display_name' => $displayName,
                'assessment_base_minor_units' => $assessmentBase,
                'employee_contribution_minor_units' => $employeeContribution,
                'employer_contribution_minor_units' => $employerContribution,
                'total_contribution_minor_units' => $totalContribution,
            ];
        }
        ksort($grouped, SORT_STRING);
        $normalized = [];
        foreach ($grouped as $reference => $people) {
            ksort($people, SORT_NUMERIC);
            $normalized[$reference] = array_values($people);
        }

        return $normalized;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array{
     *   person_count:int,
     *   assessment_base_minor_units:int,
     *   employee_contribution_minor_units:int,
     *   employer_contribution_minor_units:int,
     *   total_contribution_minor_units:int
     * }>
     */
    private function liabilities(array $rows): array
    {
        $liabilities = [];
        foreach ($rows as $index => $row) {
            $code = $this->insurerCode(
                $row['insurer_code'] ?? null,
                "insurer_liabilities.{$index}.insurer_code",
            );
            $reference = "i{$code}";
            if (isset($liabilities[$reference])) {
                throw new HealthInsuranceOverviewException(
                    'health_insurance_insurer_duplicate',
                    "Zdravotní výsledek obsahuje pojišťovnu {$code} vícekrát.",
                );
            }
            $liabilities[$reference] = [
                'person_count' => $this->nonNegativeInt(
                    $row['person_count'] ?? null,
                    "insurer_liabilities.{$index}.person_count",
                ),
                'assessment_base_minor_units' => $this->minor(
                    $row['assessment_base_minor_units'] ?? null,
                    "insurer_liabilities.{$index}.assessment_base_minor_units",
                ),
                'employee_contribution_minor_units' => $this->minor(
                    $row['employee_contribution_minor_units'] ?? null,
                    "insurer_liabilities.{$index}.employee_contribution_minor_units",
                ),
                'employer_contribution_minor_units' => $this->minor(
                    $row['employer_contribution_minor_units'] ?? null,
                    "insurer_liabilities.{$index}.employer_contribution_minor_units",
                ),
                'total_contribution_minor_units' => $this->minor(
                    $row['total_contribution_minor_units'] ?? null,
                    "insurer_liabilities.{$index}.total_contribution_minor_units",
                ),
            ];
            if ($this->add(
                $liabilities[$reference]['employee_contribution_minor_units'],
                $liabilities[$reference]['employer_contribution_minor_units'],
            ) !== $liabilities[$reference]['total_contribution_minor_units']) {
                throw new HealthInsuranceOverviewException(
                    'health_insurance_totals_mismatch',
                    "Závazek pojišťovny {$code} nemá shodný součet.",
                );
            }
        }
        ksort($liabilities, SORT_STRING);

        return $liabilities;
    }

    /**
     * @param array<string,mixed> $root
     * @param array<string,array<string,int>> $liabilities
     */
    private function assertRootTotals(array $root, array $liabilities): void
    {
        foreach ([
            'assessment_base_minor_units',
            'employee_contribution_minor_units',
            'employer_contribution_minor_units',
            'total_contribution_minor_units',
        ] as $field) {
            $sum = 0;
            foreach ($liabilities as $liability) {
                $sum = $this->add($sum, $liability[$field]);
            }
            if ($sum !== $this->minor($root[$field] ?? null, "result.{$field}")) {
                throw new HealthInsuranceOverviewException(
                    'health_insurance_totals_mismatch',
                    'Součet pojišťoven neodpovídá kořenovému zdravotnímu výsledku.',
                );
            }
        }
    }

    /**
     * @param array<string,int> $totals
     * @param list<array<string,int|string>> $people
     */
    private function assertInsurerTotals(
        string $code,
        array $totals,
        array $people,
    ): void {
        if ($totals['person_count'] !== count($people)) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_totals_mismatch',
                "Počet osob pojišťovny {$code} nesouhlasí.",
            );
        }
        foreach ([
            'assessment_base_minor_units',
            'employee_contribution_minor_units',
            'employer_contribution_minor_units',
            'total_contribution_minor_units',
        ] as $field) {
            $sum = 0;
            foreach ($people as $person) {
                $value = $person[$field] ?? null;
                if (!is_int($value)) {
                    throw new \UnexpectedValueException(
                        "Částka {$field} osoby není celé číslo.",
                    );
                }
                $sum = $this->add($sum, $value);
            }
            if ($sum !== $totals[$field]) {
                throw new HealthInsuranceOverviewException(
                    'health_insurance_totals_mismatch',
                    "Součet osob pojišťovny {$code} nesouhlasí.",
                );
            }
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshotHash(
        array $snapshot,
        string $expectedHash,
        string $label,
    ): void {
        if (!hash_equals(
            $expectedHash,
            hash('sha256', CanonicalJson::encode($snapshot)),
        )) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_snapshot_hash_mismatch',
                "Otisk {$label} nesouhlasí.",
            );
        }
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_source_invalid',
                "{$field} není objekt.",
            );
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new HealthInsuranceOverviewException(
                    'health_insurance_source_invalid',
                    "{$field} obsahuje neplatný klíč.",
                );
            }
            $normalized[$key] = $item;
        }

        return $normalized;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_source_invalid',
                "{$field} není seznam.",
            );
        }
        $normalized = [];
        foreach ($value as $index => $row) {
            $normalized[] = $this->object(
                $row,
                "{$field}.{$index}",
            );
        }

        return $normalized;
    }

    private function insurerCode(mixed $value, string $field): string
    {
        // Přehled o platbě pojistného míří na konkrétní pojišťovnu, takže se
        // kód ověřuje proti číselníku, ne jen na tvar tří číslic.
        if (!is_string($value) || !HealthInsurers::isValid($value)) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_insurer_invalid',
                sprintf(
                    '%s: %s',
                    $field,
                    HealthInsurers::invalidCodeMessage(is_string($value) ? $value : ''),
                ),
            );
        }

        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_source_invalid',
                "{$field} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_source_invalid',
                "{$field} musí být nezáporné celé číslo.",
            );
        }

        return $value;
    }

    private function minor(mixed $value, string $field): int
    {
        return $this->nonNegativeInt($value, $field);
    }

    private function nonEmptyString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new HealthInsuranceOverviewException(
                'health_insurance_source_invalid',
                "{$field} musí být neprázdný text.",
            );
        }

        return $value;
    }

    private function hash(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_source_invalid',
                "{$field} není platný SHA-256.",
            );
        }

        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1
        ) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_source_invalid',
                "{$field} není platné datum.",
            );
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_source_invalid',
                "{$field} není platné datum.",
            );
        }

        return $value;
    }

    private function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new HealthInsuranceOverviewException(
                'health_insurance_amount_overflow',
                'Součet zdravotního přehledu přetekl.',
            );
        }

        return $left + $right;
    }
}
