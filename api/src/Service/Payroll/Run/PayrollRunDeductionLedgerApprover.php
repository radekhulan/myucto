<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollNetRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;

final class PayrollRunDeductionLedgerApprover
{
    private const SAVEPOINT = 'payroll_deduction_approval';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRunRepository $runs,
        private readonly PayrollNetRepository $net,
    ) {}

    /**
     * @param array<mixed> $snapshot
     * @return array<string,mixed>
     */
    public function prepareCorrectionSnapshot(
        int $supplierId,
        int $runId,
        array $snapshot,
    ): array {
        if ($supplierId <= 0 || $runId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a mzdový běh musí mít platná ID.',
            );
        }

        return $this->transactional(function () use (
            $supplierId,
            $runId,
            $snapshot,
        ): array {
            $run = $this->runs->lock($supplierId, $runId);
            if ($run === null) {
                throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
            }
            $runNet = [];
            foreach (
                $this->net->deductionMovementsForRun($supplierId, $runId)
                as $movement
            ) {
                $key = self::key(
                    $movement['employee_id'],
                    $movement['agreement_id'],
                );
                $runNet[$key] = self::checkedAdd(
                    $runNet[$key] ?? 0,
                    $movement['amount_minor'],
                    'Součet srážek mzdového běhu překročil číselný limit.',
                );
            }

            $snapshot = self::object($snapshot, 'input_snapshot');
            $people = self::rows(
                $snapshot['people'] ?? null,
                'input_snapshot.people',
            );
            foreach ($people as &$person) {
                $employee = self::object(
                    $person['employee'] ?? null,
                    'input_snapshot.person.employee',
                );
                $employeeId = self::positiveInt(
                    $employee['id'] ?? null,
                    'input_snapshot.person.employee.id',
                );
                $agreements = self::rows(
                    $person['deduction_agreements'] ?? null,
                    'input_snapshot.person.deduction_agreements',
                );
                foreach ($agreements as &$agreement) {
                    $agreementId = self::positiveInt(
                        $agreement['id'] ?? null,
                        'input_snapshot.deduction_agreement.id',
                    );
                    $withheld = self::nonNegativeInt(
                        $agreement['withheld_total_minor'] ?? null,
                        'input_snapshot.deduction_agreement.withheld_total_minor',
                    );
                    $movement = $runNet[self::key($employeeId, $agreementId)] ?? 0;
                    if ($movement < 0 || $movement > $withheld) {
                        throw new \DomainException(
                            'Stav dohody neodpovídá schváleným srážkám mzdového běhu.',
                        );
                    }
                    $agreement['withheld_total_minor'] = $withheld - $movement;
                }
                unset($agreement);
                $person['deduction_agreements'] = $agreements;
            }
            unset($person);
            $snapshot['people'] = $people;

            return $snapshot;
        });
    }

    public function approve(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        if ($supplierId <= 0 || $revisionId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, revize a schvalovatel musí mít platná ID.',
            );
        }

        $this->transactional(function () use (
            $supplierId,
            $revisionId,
            $actorUserId,
        ): void {
            $revision = $this->runs->revision($supplierId, $revisionId);
            if ($revision === null) {
                throw new \OutOfBoundsException('Mzdová revize nebyla nalezena.');
            }
            if (($revision['status'] ?? null) !== 'approved') {
                throw new \DomainException(
                    'Srážky lze promítnout jen ze schválené revize.',
                );
            }
            $runId = self::positiveInt(
                $revision['run_id'] ?? null,
                'revision.run_id',
            );
            if ($this->runs->lock($supplierId, $runId) === null) {
                throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
            }
            $currentInput = self::object(
                $revision['input_snapshot'] ?? null,
                'revision.input_snapshot',
            );
            $currentResult = self::object(
                $revision['result_snapshot'] ?? null,
                'revision.result_snapshot',
            );
            $currentAgreements = $this->agreementOwners($currentInput);
            $currentTargets = $this->deductionTargets(
                $currentResult,
                $currentAgreements,
            );

            $previousTargets = [];
            $previousAgreements = [];
            $previousRevisionId = $revision['previous_revision_id'] ?? null;
            if (($revision['revision_kind'] ?? null) === 'correction') {
                $previousRevisionId = self::positiveInt(
                    $previousRevisionId,
                    'revision.previous_revision_id',
                );
                $previous = $this->runs->revision(
                    $supplierId,
                    $previousRevisionId,
                );
                if ($previous === null
                    || ($previous['status'] ?? null) !== 'approved'
                    || ($previous['run_id'] ?? null) !== $runId
                ) {
                    throw new \DomainException(
                        'Korekce nenavazuje na schválenou revizi stejného běhu.',
                    );
                }
                $previousInput = self::object(
                    $previous['input_snapshot'] ?? null,
                    'previous_revision.input_snapshot',
                );
                $previousAgreements = $this->agreementOwners($previousInput);
                $previousTargets = $this->deductionTargets(
                    self::object(
                        $previous['result_snapshot'] ?? null,
                        'previous_revision.result_snapshot',
                    ),
                    $previousAgreements,
                );
            } elseif ($previousRevisionId !== null) {
                $previous = $this->runs->revision(
                    $supplierId,
                    self::positiveInt(
                        $previousRevisionId,
                        'revision.previous_revision_id',
                    ),
                );
                if ($previous === null
                    || ($previous['run_id'] ?? null) !== $runId
                ) {
                    throw new \DomainException(
                        'Běžná revize nenavazuje na předchozí pokus stejného mzdového běhu.',
                    );
                }
                if (($previous['revision_kind'] ?? null) !== 'regular'
                    || !in_array(
                        $previous['status'] ?? null,
                        ['snapshot', 'calculated', 'reviewed'],
                        true,
                    )
                    || $this->runs->latestApprovedRevision(
                        $supplierId,
                        $runId,
                        self::positiveInt(
                            $revision['revision_no'] ?? null,
                            'revision.revision_no',
                        ),
                    ) !== null
                ) {
                    throw new \DomainException(
                        'Běžná revize nesmí měnit dříve schválené srážky.',
                    );
                }
            }

            $keys = array_unique([
                ...array_keys($currentTargets),
                ...array_keys($previousTargets),
            ]);
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                $identity = $currentAgreements[$key]
                    ?? $previousAgreements[$key]
                    ?? throw new \LogicException('Chybí identita dohody o srážce.');
                if (isset($currentAgreements[$key], $previousAgreements[$key])
                    && (
                        $currentAgreements[$key]['employee_id']
                            !== $previousAgreements[$key]['employee_id']
                        || $currentAgreements[$key]['agreement_id']
                            !== $previousAgreements[$key]['agreement_id']
                    )
                ) {
                    throw new \DomainException(
                        'Dohoda o srážce změnila v neměnném snapshotu vlastníka.',
                    );
                }
                $target = $currentTargets[$key] ?? 0;
                $previousTarget = $previousTargets[$key] ?? 0;
                $delta = self::checkedSubtract(
                    $target,
                    $previousTarget,
                    'Rozdíl srážky překročil číselný limit.',
                );
                $alreadyStored = $this->net->deductionNetForRevision(
                    $supplierId,
                    $revisionId,
                    $identity['employee_id'],
                    $identity['agreement_id'],
                );
                if (($delta >= 0
                        && ($alreadyStored < 0 || $alreadyStored > $delta))
                    || ($delta < 0
                        && ($alreadyStored > 0 || $alreadyStored < $delta))
                ) {
                    throw new \DomainException(
                        'Revize už obsahuje rozporné pohyby dohody o srážce.',
                    );
                }
                $outstanding = self::checkedSubtract(
                    $delta,
                    $alreadyStored,
                    'Zbývající pohyb srážky překročil číselný limit.',
                );
                if ($outstanding > 0) {
                    $this->appendWithholding(
                        $supplierId,
                        $revisionId,
                        $actorUserId,
                        $identity,
                        $target,
                        $previousTarget,
                        $outstanding,
                    );
                } elseif ($outstanding < 0) {
                    $this->appendReversals(
                        $supplierId,
                        $runId,
                        $revisionId,
                        $actorUserId,
                        $identity,
                        $target,
                        $previousTarget,
                        -$outstanding,
                    );
                }
            }
        });
    }

    /**
     * @param array{employee_id:int,agreement_id:int} $identity
     */
    private function appendWithholding(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
        array $identity,
        int $target,
        int $previousTarget,
        int $amount,
    ): void {
        $agreementId = $identity['agreement_id'];
        $this->net->appendLedgerMovement(
            $supplierId,
            $agreementId,
            $revisionId,
            $identity['employee_id'],
            'withheld',
            $amount,
            "payroll-run-deduction:v1:revision:{$revisionId}:agreement:{$agreementId}:withheld",
            null,
            [
                'current_target_minor' => $target,
                'delta_minor' => $amount,
                'previous_target_minor' => $previousTarget,
                'source' => 'approved_payroll_revision',
            ],
            $actorUserId,
        );
    }

    /**
     * @param array{employee_id:int,agreement_id:int} $identity
     */
    private function appendReversals(
        int $supplierId,
        int $runId,
        int $revisionId,
        int $actorUserId,
        array $identity,
        int $target,
        int $previousTarget,
        int $amount,
    ): void {
        $remaining = $amount;
        foreach ($this->net->availableWithholdingsForRun(
            $supplierId,
            $runId,
            $identity['employee_id'],
            $identity['agreement_id'],
        ) as $source) {
            $reversed = min($remaining, $source['available_minor']);
            $agreementId = $identity['agreement_id'];
            $sourceId = $source['id'];
            $this->net->appendLedgerMovement(
                $supplierId,
                $agreementId,
                $revisionId,
                $identity['employee_id'],
                'reversed',
                -$reversed,
                "payroll-run-deduction:v1:revision:{$revisionId}:agreement:{$agreementId}:source:{$sourceId}:reversed",
                $sourceId,
                [
                    'current_target_minor' => $target,
                    'delta_minor' => -$amount,
                    'previous_target_minor' => $previousTarget,
                    'source' => 'approved_payroll_correction',
                ],
                $actorUserId,
            );
            $remaining -= $reversed;
            if ($remaining === 0) {
                return;
            }
        }

        throw new \DomainException(
            'Korekci srážky nelze pokrýt původními schválenými pohyby.',
        );
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,array{
     *   employee_id:int,
     *   agreement_id:int,
     *   requested_minor:int
     * }>
     */
    private function agreementOwners(array $snapshot): array
    {
        $result = [];
        foreach (self::rows($snapshot['people'] ?? [], 'snapshot.people') as $person) {
            $employee = self::object(
                $person['employee'] ?? null,
                'snapshot.person.employee',
            );
            $employeeId = self::positiveInt(
                $employee['id'] ?? null,
                'snapshot.person.employee.id',
            );
            foreach (self::rows(
                $person['deduction_agreements'] ?? null,
                'snapshot.person.deduction_agreements',
            ) as $agreement) {
                $agreementId = self::positiveInt(
                    $agreement['id'] ?? null,
                    'snapshot.deduction_agreement.id',
                );
                $key = self::key($employeeId, $agreementId);
                if (isset($result[$key])) {
                    throw new \DomainException(
                        'Snapshot obsahuje dohodu o srážce vícekrát.',
                    );
                }
                self::nonNegativeInt(
                    $agreement['withheld_total_minor'] ?? null,
                    'snapshot.deduction_agreement.withheld_total_minor',
                );
                $result[$key] = [
                    'employee_id' => $employeeId,
                    'agreement_id' => $agreementId,
                    'requested_minor' => self::nonNegativeInt(
                        $agreement['requested_minor'] ?? null,
                        'snapshot.deduction_agreement.requested_minor',
                    ),
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $resultSnapshot
     * @param array<string,array{
     *   employee_id:int,
     *   agreement_id:int,
     *   requested_minor:int
     * }> $agreements
     * @return array<string,int>
     */
    private function deductionTargets(
        array $resultSnapshot,
        array $agreements,
    ): array {
        $targets = [];
        foreach (
            self::rows($resultSnapshot['people'] ?? [], 'result.people')
            as $person
        ) {
            $employeeId = self::positiveInt(
                $person['employee_id'] ?? null,
                'result.person.employee_id',
            );
            $statutory = self::object(
                $person['statutory'] ?? null,
                'result.person.statutory',
            );
            $netPay = self::object(
                $statutory['net_pay'] ?? null,
                'result.person.statutory.net_pay',
            );
            foreach (self::rows(
                $netPay['deductions'] ?? null,
                'result.person.statutory.net_pay.deductions',
            ) as $deduction) {
                $reference = $deduction['deduction_reference'] ?? null;
                if (!is_string($reference)
                    || preg_match('/^agreement:([1-9][0-9]*)$/D', $reference, $match) !== 1
                ) {
                    throw new \DomainException(
                        'Výsledek obsahuje neplatnou referenci dohody o srážce.',
                    );
                }
                $agreementId = self::positiveDecimal(
                    $match[1],
                    'result.deduction.agreement_id',
                );
                $key = self::key($employeeId, $agreementId);
                if (!isset($agreements[$key])) {
                    throw new \DomainException(
                        'Výsledek odkazuje na dohodu mimo neměnný vstupní snapshot.',
                    );
                }
                if (isset($targets[$key])) {
                    throw new \DomainException(
                        'Výsledek obsahuje dohodu o srážce vícekrát.',
                    );
                }
                $requested = self::nonNegativeInt(
                    $deduction['requested_minor_units'] ?? null,
                    'result.deduction.requested_minor_units',
                );
                $applied = self::nonNegativeInt(
                    $deduction['applied_minor_units'] ?? null,
                    'result.deduction.applied_minor_units',
                );
                $unapplied = self::nonNegativeInt(
                    $deduction['unapplied_minor_units'] ?? null,
                    'result.deduction.unapplied_minor_units',
                );
                if ($requested !== $agreements[$key]['requested_minor']
                    || $applied > $requested
                    || $unapplied !== $requested - $applied
                ) {
                    throw new \DomainException(
                        'Výsledek srážky neodpovídá neměnnému vstupnímu snapshotu.',
                    );
                }
                $targets[$key] = $applied;
            }
        }

        return $targets;
    }

    private static function key(int $employeeId, int $agreementId): string
    {
        return "{$employeeId}:{$agreementId}";
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
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

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        $result = [];
        foreach ($value as $index => $row) {
            $result[] = self::object($row, "{$field}.{$index}");
        }

        return $result;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException(
                "{$field} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    private static function positiveDecimal(string $value, string $field): int
    {
        if (strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX)
                && strcmp($value, (string) PHP_INT_MAX) > 0)
        ) {
            throw new \OverflowException("{$field} překročilo číselný limit.");
        }

        return self::positiveInt((int) $value, $field);
    }

    private static function nonNegativeInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException(
                "{$field} musí být nezáporné celé číslo.",
            );
        }

        return $value;
    }

    private static function checkedAdd(int $left, int $right, string $message): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException($message);
        }

        return $left + $right;
    }

    private static function checkedSubtract(
        int $left,
        int $right,
        string $message,
    ): int {
        if (($right > 0 && $left < PHP_INT_MIN + $right)
            || ($right < 0 && $left > PHP_INT_MAX + $right)
        ) {
            throw new \OverflowException($message);
        }

        return $left - $right;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } else {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
