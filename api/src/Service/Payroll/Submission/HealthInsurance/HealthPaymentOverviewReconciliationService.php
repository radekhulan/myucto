<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class HealthPaymentOverviewReconciliationService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   liability_ids:list<int>,expected_minor:int,liability_minor:int,
     *   liability_difference_minor:int,bank_settled_minor:int,
     *   outgoing_remaining_minor:int,incoming_remaining_minor:int,
     *   bank_remaining_minor:int,state:string,closing_blocked:bool,
     *   blockers:list<string>
     * }
     */
    public function forOverview(HealthPaymentOverview $overview): array
    {
        return $this->forOverviews([$overview])[0];
    }

    /**
     * Hromadná varianta pro provozní sweep: jedna kontrola revize a jeden
     * ledger dotaz bez N+1 podle počtu zdravotních pojišťoven.
     *
     * @param list<HealthPaymentOverview> $overviews
     * @return list<array{
     *   liability_ids:list<int>,expected_minor:int,liability_minor:int,
     *   liability_difference_minor:int,bank_settled_minor:int,
     *   outgoing_remaining_minor:int,incoming_remaining_minor:int,
     *   bank_remaining_minor:int,state:string,closing_blocked:bool,
     *   blockers:list<string>
     * }>
     */
    public function forOverviews(array $overviews): array
    {
        if ($overviews === []) {
            return [];
        }
        $first = $overviews[0];
        if (!$first instanceof HealthPaymentOverview) {
            throw new \InvalidArgumentException('PPZ sweep obsahuje neplatný přehled.');
        }
        $references = [];
        foreach ($overviews as $overview) {
            if (!$overview instanceof HealthPaymentOverview
                || $overview->supplierId !== $first->supplierId
                || $overview->runId !== $first->runId
                || $overview->revisionId !== $first->revisionId
                || $overview->revisionNo !== $first->revisionNo
            ) {
                throw new \InvalidArgumentException(
                    'PPZ sweep smí obsahovat jen jednu tenantovou revizi.',
                );
            }
            $reference = 'health-insurance:i' . $overview->insurerCode;
            if (isset($references[$reference])) {
                throw new \InvalidArgumentException(
                    'PPZ sweep obsahuje duplicitní pojišťovnu.',
                );
            }
            $references[$reference] = true;
        }

        $revision = $this->db->pdo()->prepare(
            'SELECT run_id, revision_no
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?',
        );
        $revision->execute([$first->supplierId, $first->revisionId]);
        $run = $revision->fetch(PDO::FETCH_ASSOC);
        if (!is_array($run) || array_is_list($run)
            || (int) ($run['run_id'] ?? 0) !== $first->runId
            || (int) ($run['revision_no'] ?? 0) !== $first->revisionNo
        ) {
            throw new \OutOfBoundsException(
                'Mzdová revize PPZ nebyla nalezena v aktuální firmě.',
            );
        }

        $placeholders = implode(', ', array_fill(0, count($references), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.liability_reference, liability.id,
                    liability.direction, liability.amount_minor,
                    COALESCE((
                      SELECT SUM(payment_match.amount_minor)
                        FROM payroll_payment_matches payment_match
                       WHERE payment_match.supplier_id = liability.supplier_id
                         AND payment_match.liability_id = liability.id
                    ), 0) AS settled_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions liability_revision
                 ON liability_revision.supplier_id = liability.supplier_id
                AND liability_revision.id = liability.revision_id
              WHERE liability.supplier_id = ?
                AND liability_revision.run_id = ?
                AND liability_revision.revision_no <= ?
                AND liability.liability_kind = "health_insurance"
                AND liability.liability_reference IN (' . $placeholders . ')
                AND liability.currency_code = "CZK"
              ORDER BY liability.liability_reference,
                       liability_revision.revision_no, liability.id',
        );
        $statement->execute(array_merge([
            $first->supplierId,
            (int) $run['run_id'],
            (int) $run['revision_no'],
        ], array_keys($references)));

        $byReference = array_fill_keys(array_keys($references), []);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \UnexpectedValueException(
                    'Platební ledger PPZ vrátil neplatný řádek.',
                );
            }
            $reference = (string) ($row['liability_reference'] ?? '');
            if (!array_key_exists($reference, $byReference)) {
                throw new \UnexpectedValueException(
                    'Platební ledger PPZ vrátil neznámou pojišťovnu.',
                );
            }
            $byReference[$reference][] = $row;
        }

        $result = [];
        foreach ($overviews as $overview) {
            $result[] = $this->reconcileRows(
                $overview,
                $byReference['health-insurance:i' . $overview->insurerCode],
            );
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{
     *   liability_ids:list<int>,expected_minor:int,liability_minor:int,
     *   liability_difference_minor:int,bank_settled_minor:int,
     *   outgoing_remaining_minor:int,incoming_remaining_minor:int,
     *   bank_remaining_minor:int,state:string,closing_blocked:bool,
     *   blockers:list<string>
     * }
     */
    private function reconcileRows(
        HealthPaymentOverview $overview,
        array $rows,
    ): array {
        $liabilityIds = [];
        $liabilityMinor = 0;
        $bankSettledMinor = 0;
        $outgoingRequired = 0;
        $outgoingSettled = 0;
        $incomingRequired = 0;
        $incomingSettled = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $amount = (int) ($row['amount_minor'] ?? 0);
            $settled = (int) ($row['settled_minor'] ?? 0);
            $direction = $row['direction'] ?? null;
            if ($id <= 0 || $amount <= 0 || !in_array(
                $direction,
                ['outgoing', 'incoming'],
                true,
            ) || $settled < 0 || $settled > $amount) {
                throw new \UnexpectedValueException(
                    'Platební ledger PPZ má neplatné součty.',
                );
            }
            $sign = $direction === 'outgoing' ? 1 : -1;
            $liabilityIds[] = $id;
            $liabilityMinor += $sign * $amount;
            $bankSettledMinor += $sign * $settled;
            if ($direction === 'outgoing') {
                $outgoingRequired += $amount;
                $outgoingSettled += $settled;
            } else {
                $incomingRequired += $amount;
                $incomingSettled += $settled;
            }
        }

        $expected = $overview->totals['total_contribution_minor_units'] ?? null;
        if (!is_int($expected) || $expected < 0) {
            throw new \UnexpectedValueException('PPZ nemá platnou částku pojistného.');
        }
        $liabilityDifference = $expected - $liabilityMinor;
        $outgoingRemaining = $outgoingRequired - $outgoingSettled;
        $incomingRemaining = $incomingRequired - $incomingSettled;
        $bankRemaining = $liabilityMinor - $bankSettledMinor;
        $blockers = [];
        if ($liabilityIds === []) {
            $blockers[] = 'liability_missing';
        }
        if ($liabilityDifference !== 0) {
            $blockers[] = 'liability_difference';
        }
        if ($outgoingRemaining !== 0 || $incomingRemaining !== 0) {
            $blockers[] = 'bank_unsettled';
        }
        $state = $liabilityIds === []
            ? 'missing'
            : ($liabilityDifference !== 0
                ? 'mismatch'
                : ($outgoingRemaining === 0 && $incomingRemaining === 0
                    ? 'settled'
                    : (($outgoingSettled > 0 || $incomingSettled > 0)
                        ? 'partially_settled'
                        : 'open')));

        return [
            'liability_ids' => $liabilityIds,
            'expected_minor' => $expected,
            'liability_minor' => $liabilityMinor,
            'liability_difference_minor' => $liabilityDifference,
            'bank_settled_minor' => $bankSettledMinor,
            'outgoing_remaining_minor' => $outgoingRemaining,
            'incoming_remaining_minor' => $incomingRemaining,
            'bank_remaining_minor' => $bankRemaining,
            'state' => $state,
            'closing_blocked' => $blockers !== [],
            'blockers' => $blockers,
        ];
    }
}
