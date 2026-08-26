<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Document\PayslipDocumentSnapshotMapper;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsApprover;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollRunCalculationPipeline
{
    private const FINAL_SCHEMA_VERSION = 'payroll-run-result.v2';
    private readonly PayslipDocumentSnapshotMapper $payslipDocuments;

    public function __construct(
        private readonly PayrollRunCalculator $calculator,
        private readonly PayrollRunGarnishmentProcessor $garnishments,
        private readonly ?PayrollRunStatutoryCalculationService $statutory = null,
        private readonly ?PayrollRunStatutoryAccumulatorApprover
            $statutoryAccumulatorApprover = null,
        private readonly ?PayrollRunDeductionLedgerApprover
            $deductionLedgerApprover = null,
        private readonly ?PayrollRiskySavingsApprover $riskySavingsApprover = null,
    ) {
        $this->payslipDocuments = new PayslipDocumentSnapshotMapper();
    }

    /**
     * @param array<mixed> $snapshot
     * @return array<string,mixed>
     */
    public function calculate(
        array $snapshot,
        ?int $supplierId = null,
        ?int $revisionId = null,
        ?int $actorUserId = null,
    ): array
    {
        $snapshot = self::object($snapshot, 'snapshot');
        $result = $this->calculator->calculate($snapshot);
        $intermediateSchemaVersion = $result['schema_version'] ?? null;
        if (!is_string($intermediateSchemaVersion)) {
            throw new \UnexpectedValueException(
                'Dílčí výsledek mzdového běhu nemá verzi schématu.',
            );
        }

        if (($snapshot['schema_version'] ?? null) === 'payroll-run-input.v2'
            && $this->statutory !== null
        ) {
            $baseResult = $result;
            $result['statutory'] = $this->statutory->calculateAndPersist(
                $supplierId ?? throw new \LogicException('Chybí firma zákonného výpočtu.'),
                $revisionId ?? throw new \LogicException('Chybí revize zákonného výpočtu.'),
                $actorUserId,
                $snapshot,
                $result,
                fn (array $netBeforeDeductions): array =>
                    $this->garnishments->voluntaryDeductionCapacities(
                        $snapshot,
                        $baseResult,
                        $netBeforeDeductions,
                    ),
            );
            $result = $this->attachStatutoryPeople($result);
        }

        $result = $this->garnishments->calculate($snapshot, $result);
        if (($snapshot['schema_version'] ?? null) === 'payroll-run-input.v2'
            && isset($result['statutory'])
        ) {
            $result = $this->payslipDocuments->attach($snapshot, $result);
        }
        if (($result['schema_version'] ?? null) !== $intermediateSchemaVersion) {
            throw new \UnexpectedValueException(
                'Dílčí procesor nesmí měnit verzi výsledného schématu.',
            );
        }
        $result['schema_version'] = self::FINAL_SCHEMA_VERSION;

        return $result;
    }

    /** @param array<mixed> $result */
    public function storeApproved(
        int $supplierId,
        int $revisionId,
        array $result,
    ): void {
        $this->garnishments->storeApproved(
            $supplierId,
            $revisionId,
            self::object($result, 'result'),
        );
        $this->riskySavingsApprover?->storeApproved(
            $supplierId,
            $revisionId,
            self::object($result, 'result'),
        );
    }

    public function storeApprovedStatutoryAccumulators(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        $this->statutoryAccumulatorApprover?->approve(
            $supplierId,
            $revisionId,
            $actorUserId,
        );
    }

    public function prepareCorrectionSnapshot(
        int $supplierId,
        int $runId,
        PayrollRunInputSnapshot $snapshot,
    ): PayrollRunInputSnapshot {
        if ($this->deductionLedgerApprover === null) {
            return $snapshot;
        }
        $data = $this->deductionLedgerApprover->prepareCorrectionSnapshot(
            $supplierId,
            $runId,
            $snapshot->data,
        );
        $json = CanonicalJson::encode($data);

        return new PayrollRunInputSnapshot(
            $data,
            $json,
            hash('sha256', $json),
            $snapshot->rulesetManifestHash,
            $snapshot->validations,
        );
    }

    public function storeApprovedDeductions(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        $this->deductionLedgerApprover?->approve(
            $supplierId,
            $revisionId,
            $actorUserId,
        );
    }

    /**
     * @param array<mixed> $value
     * @return array<string,mixed>
     */
    private static function object(array $value, string $field): array
    {
        if (array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "{$field} musí mít textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function attachStatutoryPeople(array $result): array
    {
        $statutory = $result['statutory'] ?? null;
        if (!is_array($statutory) || array_is_list($statutory)) {
            throw new \UnexpectedValueException('Zákonný výsledek není objekt.');
        }
        $byEmployee = [];
        $people = $statutory['people'] ?? [];
        if (!is_array($people) || !array_is_list($people)) {
            throw new \UnexpectedValueException('Osoby zákonného výsledku nejsou seznam.');
        }
        foreach ($people as $person) {
            if (!is_array($person) || array_is_list($person)) {
                throw new \UnexpectedValueException('Osoba zákonného výsledku není objekt.');
            }
            $reference = $person['person_reference'] ?? null;
            if (!is_string($reference)
                || preg_match('/^employee:([1-9][0-9]*)$/D', $reference, $match) !== 1
            ) {
                throw new \UnexpectedValueException('Osoba zákonného výsledku nemá identitu.');
            }
            $byEmployee[(int) $match[1]] = $person;
        }
        $resultPeople = $result['people'] ?? null;
        if (!is_array($resultPeople) || !array_is_list($resultPeople)) {
            throw new \UnexpectedValueException('Výsledek nemá seznam osob.');
        }
        foreach ($resultPeople as &$person) {
            if (!is_array($person) || array_is_list($person)) {
                throw new \UnexpectedValueException('Výsledek osoby není objekt.');
            }
            $employeeId = $person['employee_id'] ?? null;
            if (!is_int($employeeId) || $employeeId <= 0) {
                throw new \UnexpectedValueException('Výsledek osoby nemá identitu.');
            }
            $person['statutory'] = $byEmployee[$employeeId] ?? [
                'person_reference' => "employee:{$employeeId}",
                'status' => 'manual_review',
                'issues' => $statutory['issues'] ?? ['statutory_result_missing'],
            ];
        }
        unset($person);
        $result['people'] = $resultPeople;
        return $result;
    }
}
