<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PreparedApprovedRevisionPayslipBatch
{
    /**
     * @param list<array{
     *   employee_id:int,
     *   source_hash:string,
     *   artifact:PayrollArtifact
     * }> $items
     */
    public function __construct(
        public int $supplierId,
        public int $runId,
        public int $revisionId,
        public string $sourceFingerprint,
        public array $items,
    ) {
        if ($supplierId <= 0 || $runId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Identita připravené dávky výplatních pásek není platná.',
            );
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceFingerprint) !== 1) {
            throw new \InvalidArgumentException(
                'Otisk připravené dávky výplatních pásek není platný.',
            );
        }
        if ($items === []) {
            throw new \InvalidArgumentException(
                'Připravená dávka výplatních pásek je prázdná.',
            );
        }
        $employeeIds = [];
        foreach ($items as $item) {
            $employeeId = $item['employee_id'];
            $sourceHash = $item['source_hash'];
            $artifact = $item['artifact'];
            if (
                $employeeId <= 0
                || isset($employeeIds[$employeeId])
                || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
                || $artifact->kind !== PayrollDocumentKind::Payslip
                || !hash_equals($sourceHash, $artifact->sourceSnapshotHash)
            ) {
                throw new \InvalidArgumentException(
                    'Připravená dávka obsahuje neplatnou výplatní pásku.',
                );
            }
            $employeeIds[$employeeId] = true;
        }
    }
}
