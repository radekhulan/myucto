<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Payment\PayrollPaydayResolver;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollMonthlyChecklistService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1BatchService;

final class PayrollMonthGuideService
{
    public function __construct(
        private readonly PayrollRunRepository $runs,
        private readonly PayrollPaydayResolver $payday,
        private readonly PayrollRunReadinessService $readiness,
        private readonly PayrollWarningSuppressionService $warningSuppressions,
        private readonly PayrollRunAutoSettlementService $autoSettlement,
        private readonly PayrollMonthlyChecklistService $checklist,
        private readonly PayrollRegistrationA1BatchService $registrationA1,
        private readonly AttendanceImportService $attendanceImports,
        private readonly PayrollDocumentRepository $documents,
    ) {}

    /**
     * @param array{submissions:bool,imports:bool,documents:bool} $access
     * @return array<string,mixed>
     */
    public function guide(
        int $supplierId,
        string $period,
        string $environment,
        ?int $userId,
        array $access,
    ): array {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new \InvalidArgumentException('Období musí být ve tvaru RRRR-MM.');
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException('Prostředí musí být test nebo production.');
        }
        $periodStart = "{$period}-01";
        $periodEnd = (new \DateTimeImmutable($periodStart))->format('Y-m-t');

        $items = $this->runs->list(
            $supplierId,
            $periodStart,
            PayrollRunRepository::LIST_MAX_LIMIT,
            0,
        )['items'];
        $suppressions = $this->warningSuppressions->activeSet($supplierId);
        $runs = [];
        $locked = false;
        $paymentDate = null;
        foreach ($items as $item) {
            $status = (string) $item['status'];
            $revisionId = ($item['revision_id'] ?? null) === null ? null : (int) $item['revision_id'];
            $validations = $revisionId === null
                ? []
                : $suppressions->filterRows($this->runs->validations($supplierId, $revisionId))['visible'];
            $runs[] = [
                'id' => (int) $item['id'],
                'status' => $status,
                'office_id' => ($item['office_id'] ?? null) === null ? null : (int) $item['office_id'],
                'approve_blocker_count' => count(array_filter(
                    $validations,
                    static fn (array $validation): bool => PayrollMonthGuide::validationStops($validation),
                )),
                'validations' => $validations,
                'payment_coverage' => $this->autoSettlement->coverageSummary($supplierId, $revisionId),
            ];
            if ($status !== PayrollRunStatus::DRAFT->value) {
                $locked = true;
            }
            $paymentDate = (string) $item['payment_date'];
        }

        return PayrollMonthGuide::build([
            'period' => $period,
            'locked' => $locked,
            'runs' => $runs,
            'readiness' => $locked ? null : $this->readiness($supplierId, $periodStart, $paymentDate),
            'attendance_batches' => $access['imports'] ? $this->attendanceBatches($supplierId, $period) : null,
            'registration_a1' => $access['submissions']
                ? $this->registrationA1($supplierId, $environment, $periodStart, $periodEnd)
                : null,
            'checklist' => $access['submissions']
                ? $this->checklistFacts($supplierId, $environment, $period, $userId)
                : null,
            'documents_total' => $access['documents'] ? $this->documentsTotal($supplierId, $periodStart) : null,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function readiness(int $supplierId, string $periodStart, ?string $paymentDate): ?array
    {
        try {
            return $this->readiness->inspect(
                $supplierId,
                $periodStart,
                $paymentDate ?? $this->payday->suggest($supplierId, $periodStart),
                null,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{count:int,persons:int}|null */
    private function attendanceBatches(int $supplierId, string $period): ?array
    {
        try {
            $batches = $this->attendanceImports->batches($supplierId, $period);
        } catch (\Throwable) {
            return null;
        }

        return [
            'count' => count($batches),
            'persons' => array_sum(array_map(
                static fn (array $batch): int => (int) ($batch['person_count'] ?? 0),
                $batches,
            )),
        ];
    }

    /** @return array<string,mixed>|null */
    private function registrationA1(int $supplierId, string $environment, string $from, string $to): ?array
    {
        try {
            $result = $this->registrationA1->candidates($supplierId, $environment, $from, $to);
        } catch (\Throwable) {
            return null;
        }
        $summary = ['total' => 0, 'preparable' => 0, 'incomplete' => 0, 'overdue' => 0, 'too_early' => 0];
        foreach ($result['items'] as $item) {
            ++$summary['total'];
            $tooEarly = ($item['deadline']['too_early'] ?? false) === true;
            if (($item['complete'] ?? false) !== true) {
                ++$summary['incomplete'];
            } elseif ($tooEarly) {
                ++$summary['too_early'];
            } else {
                ++$summary['preparable'];
            }
            if (($item['deadline']['overdue'] ?? false) === true) {
                ++$summary['overdue'];
            }
        }

        return $summary + ['from' => $result['from'], 'to' => $result['to']];
    }

    /** @return array<string,mixed>|null */
    private function checklistFacts(int $supplierId, string $environment, string $period, ?int $userId): ?array
    {
        try {
            $result = $this->checklist->checklist($supplierId, $environment, $period, $userId);
        } catch (\Throwable) {
            return null;
        }
        $hozOpen = 0;
        $unpreparedOverdue = 0;
        $jmhz = ['total' => 0, 'done' => 0];
        foreach ($result['items'] as $item) {
            $agenda = PayrollDispatchCapabilityCatalog::canonical((string) ($item['agenda_code'] ?? ''));
            $done = ($item['done'] ?? false) === true;
            if ($agenda === HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION && !$done) {
                ++$hozOpen;
            }
            if (str_starts_with($agenda, 'JMHZ')) {
                ++$jmhz['total'];
                if ($done) {
                    ++$jmhz['done'];
                }
            }
            if (!$done && ($item['is_overdue'] ?? false) === true && ($item['submission_id'] ?? null) === null) {
                ++$unpreparedOverdue;
            }
        }

        return [
            'summary' => $result['summary'],
            'hoz_open' => $hozOpen,
            'unprepared_overdue' => $unpreparedOverdue,
            'jmhz' => $jmhz,
        ];
    }

    private function documentsTotal(int $supplierId, string $periodStart): ?int
    {
        try {
            return (int) $this->documents->listForPeriod($supplierId, $periodStart, 1, 0)['total'];
        } catch (\Throwable) {
            return null;
        }
    }
}
