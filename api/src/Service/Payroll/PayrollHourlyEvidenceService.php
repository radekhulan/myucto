<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollHourlyEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollSurchargeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeLockedException;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeEvidence;

final class PayrollHourlyEvidenceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollHourlyEvidenceRepository $repository,
        private readonly PayrollHourlyEntitlementContextLoader $context,
    ) {}

    /** @return array<string,mixed> */
    public function view(int $supplierId, int $employmentId, string $periodStart, int $offset = 0): array
    {
        $this->assertEmployment($supplierId, $employmentId, $periodStart);
        $source = (new PayrollWorkedTimeEvidence($this->db))->loadMany($supplierId, [$employmentId], $periodStart)[$employmentId] ?? null;
        $source = $this->context->sourceForEvidence($supplierId, $employmentId, $periodStart, $source);
        $evidence = $this->repository->latestMany($supplierId, [$employmentId], $periodStart)[$employmentId] ?? null;
        $writable = $source !== null && in_array($source['work_source'], ['manual_summary', 'entries', 'settlement_only'], true);
        // Pojistky zápisu (roční uzávěrka) se smí ptát jen uvnitř transakce; náhled
        // si ji proto otevře jen na dotaz a nic nezapíše.
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->repository->assertWritable($supplierId, $employmentId, $periodStart);
        } catch (PayrollRunClosedException|PayrollYearClosedException|PayrollTimeLockedException) {
            $writable = false;
        } finally {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $suggested = [];
        $end = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $policies = (new PayrollSurchargeRepository($this->db))->policiesForMany($supplierId, [$employmentId], $periodStart, $end)[$employmentId] ?? [];
        foreach ($source['manual_summary']['segments'] ?? [] as $segment) {
            $mode = 'surcharge';
            foreach ($policies as $policy) {
                if ($policy['valid_from'] <= $segment['date_from'] && ($policy['valid_to'] === null || $policy['valid_to'] >= $segment['date_from'])) {
                    $mode = $policy['overtime_mode'];
                    break;
                }
            }
            $suggested[] = ['date_from' => $segment['date_from'], 'date_to' => $segment['date_to'],
                'overtime_duration_units' => $segment['overtime_duration_units'], 'overtime_mode' => $mode];
        }
        $options = $this->context->evidenceOptions($supplierId, $employmentId, $periodStart);
        $automatic = $options['automatic_assessment'];
        unset($options['automatic_assessment']);
        return ['period' => substr($periodStart, 0, 7), 'employment_id' => $employmentId,
            'work_source' => $source['work_source'] ?? 'entries',
            'row_version' => (int) ($evidence['revision_no'] ?? 0),
            'work_source_hash' => $source === null ? '' : PayrollHourlyEvidenceRepository::bindingHash($source),
            'can_write' => $writable, 'segments_suggested' => $suggested, 'evidence' => $evidence,
            'options' => $options, 'automatic_assessment' => $automatic,
            ...$this->repository->history($supplierId, $employmentId, $periodStart, $offset)];
    }

    public function save(int $supplierId, int $employmentId, string $periodStart, array $input, ?int $userId): void
    {
        $this->assertEmployment($supplierId, $employmentId, $periodStart);
        $this->repository->save($supplierId, $employmentId, $periodStart, $input, $userId,
            fn(array $payload, array $source) => $this->context->validateSelections($supplierId, $employmentId, $periodStart, $payload, $source));
    }

    private function assertEmployment(int $supplierId, int $employmentId, string $periodStart): void
    {
        $end = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employments employment
            WHERE employment.supplier_id = ? AND employment.id = ? AND EXISTS (
                SELECT 1 FROM payroll_employment_terms term WHERE term.supplier_id = employment.supplier_id
                AND term.employment_id = employment.id AND term.remuneration_type = "hourly"
                AND term.effective_from <= ? AND (term.effective_to IS NULL OR term.effective_to >= ?))');
        $stmt->execute([$supplierId, $employmentId, $end, $periodStart]);
        if ($stmt->fetchColumn() === false
            && !(new PayrollHourlyEntitlementLedger($this->db))->hasOutstanding($supplierId, $employmentId, $periodStart)
        ) throw new \OutOfBoundsException('Hodinový pracovní vztah ani dosud nevypořádaný hodinový nárok nebyl pro dané období nalezen.');
    }
}
