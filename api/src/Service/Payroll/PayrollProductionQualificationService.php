<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollProductionQualificationService
{
    private const APPROVED_REVISION_STATUS = 'approved';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollModuleStateRepository $state,
        private readonly SupportMatrix $supportMatrix,
        private readonly PayrollCompanyCapabilityService $companyCapability,
        private readonly ActivityLogger $logger,
        private readonly DocumentRepository $documents,
    ) {}

    /**
     * @param array<array-key,mixed> $evidence
     * @return array{state:array<string,mixed>,qualification:array<string,mixed>}
     */
    public function activate(
        int $supplierId,
        int $expectedStateVersion,
        string $requestedMatrixVersion,
        array $evidence,
        int $actorUserId,
    ): array {
        if ($supplierId <= 0 || $expectedStateVersion <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, verze stavu a uživatel kvalifikace musí být kladná čísla.',
            );
        }
        if ($requestedMatrixVersion !== SupportMatrix::VERSION) {
            throw new PayrollProductionQualificationException(
                'support_matrix_changed',
                'Kvalifikace musí být potvrzena proti aktuální support matrix.',
            );
        }

        $currentState = $this->state->get($supplierId);
        if (!in_array(
            $currentState['status'],
            ['setup', 'qualification_required'],
            true,
        )) {
            throw new PayrollProductionQualificationException(
                'qualification_requires_setup',
                'Produkční kvalifikaci lze dokončit pouze před ostrou aktivací.',
            );
        }
        if ((int) $currentState['row_version'] !== $expectedStateVersion) {
            throw new \MyInvoice\Repository\Payroll\PayrollStateConflictException(
                (int) $currentState['row_version'],
            );
        }

        $startPeriod = $currentState['start_period'];
        if (!is_string($startPeriod)
            || !$this->supportMatrix->supportsYear((int) substr($startPeriod, 0, 4))
        ) {
            throw new PayrollProductionQualificationException(
                'unsupported_start_period',
                'Počáteční období už aktuální support matrix nepodporuje.',
            );
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $companyCapability = $this->companyCapability->assessForUpdate(
                $supplierId,
                $startPeriod,
            );
            if (!$companyCapability['production_ready']) {
                throw new PayrollProductionQualificationException(
                    'company_capability_blocked',
                    'Produkční provoz nelze aktivovat, dokud aktuální mzdová data vyžadují nepodporovaný scénář.',
                    ['blockers' => $companyCapability['blockers']],
                );
            }
            $normalizedEvidence = $this->normalizeEvidence($supplierId, $evidence);
            $matrixJson = CanonicalJson::encode($this->supportMatrix->all());
            $matrixHash = hash('sha256', $matrixJson);
            $evidenceJson = CanonicalJson::encode($normalizedEvidence);
            $evidenceHash = hash('sha256', $evidenceJson);

            $state = $this->state->promoteToActive(
                $supplierId,
                $actorUserId,
                $expectedStateVersion,
            );
            if ($state === null) {
                throw new PayrollProductionQualificationException(
                    'qualification_requires_setup',
                    'Produkční kvalifikaci lze dokončit pouze před ostrou aktivací.',
                );
            }

            $insert = $pdo->prepare(
                'INSERT INTO payroll_production_qualifications
                    (supplier_id, module_state_row_version,
                     support_matrix_version, support_matrix_sha256,
                     evidence_json, evidence_sha256, qualified_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $state['row_version'],
                SupportMatrix::VERSION,
                $matrixHash,
                $evidenceJson,
                $evidenceHash,
                $actorUserId,
            ]);
            $qualificationId = (int) $pdo->lastInsertId();

            $documentInsert = $pdo->prepare(
                'INSERT INTO payroll_production_qualification_documents
                    (supplier_id, qualification_id, evidence_key, sequence_no,
                     document_id, document_sha256)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($this->evidenceDocuments($normalizedEvidence) as $document) {
                $documentInsert->execute([
                    $supplierId,
                    $qualificationId,
                    $document['evidence_key'],
                    $document['sequence_no'],
                    $document['document_id'],
                    $document['document_sha256'],
                ]);
            }

            $this->logger->log(
                'payroll.activation.production_qualified',
                $actorUserId,
                'payroll_production_qualification',
                $qualificationId,
                [
                    'status' => $state['status'],
                    'start_period' => $state['start_period'],
                    'module_state_row_version' => $state['row_version'],
                    'support_matrix_version' => SupportMatrix::VERSION,
                    'support_matrix_sha256' => $matrixHash,
                    'evidence_sha256' => $evidenceHash,
                ],
                null,
                null,
                $supplierId,
            );

            $qualification = $this->qualification($supplierId);
            if ($qualification === null) {
                throw new \LogicException('Uložená produkční kvalifikace nebyla nalezena.');
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ['state' => $state, 'qualification' => $qualification];
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                self::rollBackIfActive($pdo);
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function qualification(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, module_state_row_version,
                    support_matrix_version, support_matrix_sha256,
                    evidence_sha256, qualified_by, qualified_at
               FROM payroll_production_qualifications
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => self::databaseInt($row, 'id'),
            'supplier_id' => self::databaseInt($row, 'supplier_id'),
            'module_state_row_version' => self::databaseInt(
                $row,
                'module_state_row_version',
            ),
            'support_matrix_version' => self::databaseString(
                $row,
                'support_matrix_version',
            ),
            'support_matrix_sha256' => self::databaseString(
                $row,
                'support_matrix_sha256',
            ),
            'evidence_sha256' => self::databaseString($row, 'evidence_sha256'),
            'qualified_by' => $row['qualified_by'] === null
                ? null
                : self::databaseInt($row, 'qualified_by'),
            'qualified_at' => self::databaseString($row, 'qualified_at'),
        ];
    }

    /**
     * @param array<array-key,mixed> $evidence
     * @return array{
     *   parallel_runs:list<array{sequence:int,document_id:int,document_sha256:string}>,
     *   correction_scenario:array{document_id:int,document_sha256:string},
     *   recovery_drill:array{document_id:int,document_sha256:string},
     *   expert_approval:array{document_id:int,document_sha256:string},
     *   rollback_plan:array{document_id:int,document_sha256:string},
     *   post_go_live_monitoring:array{document_id:int,document_sha256:string}
     * }
     */
    private function normalizeEvidence(int $supplierId, array $evidence): array
    {
        $parallelRuns = $evidence['parallel_runs'] ?? null;
        if (!is_array($parallelRuns) || !array_is_list($parallelRuns)
            || count($parallelRuns) !== 2
        ) {
            throw new PayrollProductionQualificationException(
                'parallel_runs_required',
                'Produkční kvalifikace vyžaduje právě dva paralelní běhy.',
            );
        }

        $normalizedRuns = [];
        foreach ($parallelRuns as $index => $parallelRun) {
            if (!is_array($parallelRun)) {
                throw new PayrollProductionQualificationException(
                    'invalid_parallel_run',
                    'Doklad paralelního běhu nemá platný formát.',
                );
            }
            $runId = $this->positiveInt($parallelRun['payroll_run_id'] ?? null);
            $run = $this->approvedRun($supplierId, $runId);
            $this->assertSupportedPeriod((string) $run['period_start']);
            $normalizedRuns[] = [
                'sequence' => $index + 1,
                'payroll_run_id' => $runId,
                'period_start' => (string) $run['period_start'],
                'revision_id' => (int) $run['revision_id'],
                'approved_at' => (string) $run['approved_at'],
                ...$this->evidenceDocument(
                    $supplierId,
                    $parallelRun['document_id'] ?? null,
                ),
            ];
        }
        if ($normalizedRuns[0]['payroll_run_id'] === $normalizedRuns[1]['payroll_run_id']
            || $normalizedRuns[0]['period_start'] === $normalizedRuns[1]['period_start']
        ) {
            throw new PayrollProductionQualificationException(
                'parallel_runs_not_distinct',
                'Paralelní běhy musí patřit dvěma různým mzdovým obdobím.',
            );
        }

        $correction = $evidence['correction_scenario'] ?? null;
        if (!is_array($correction)) {
            throw new PayrollProductionQualificationException(
                'correction_evidence_required',
                'Chybí doklad opravného scénáře.',
            );
        }
        $correctionRunId = $this->positiveInt($correction['payroll_run_id'] ?? null);
        $correctionRun = $this->approvedRun($supplierId, $correctionRunId);
        if ($correctionRun['revision_kind'] !== 'correction') {
            throw new PayrollProductionQualificationException(
                'correction_run_required',
                'Opravný scénář musí odkazovat na schválenou opravnou revizi.',
            );
        }
        $this->assertSupportedPeriod((string) $correctionRun['period_start']);

        $recovery = $evidence['recovery_drill'] ?? null;
        if (!is_array($recovery)) {
            throw new PayrollProductionQualificationException(
                'recovery_evidence_required',
                'Chybí doklad recovery drillu.',
            );
        }

        $expertApproval = $evidence['expert_approval'] ?? null;
        if (!is_array($expertApproval)) {
            throw new PayrollProductionQualificationException(
                'expert_approval_required',
                'Chybí doložené odborné schválení produkční kvalifikace.',
            );
        }
        $rollbackPlan = $evidence['rollback_plan'] ?? null;
        if (!is_array($rollbackPlan)) {
            throw new PayrollProductionQualificationException(
                'rollback_plan_required',
                'Chybí doložený plán bezpečného návratu.',
            );
        }
        $monitoring = $evidence['post_go_live_monitoring'] ?? null;
        if (!is_array($monitoring)) {
            throw new PayrollProductionQualificationException(
                'post_go_live_monitoring_required',
                'Chybí doložený plán post-go-live dohledu.',
            );
        }

        return [
            'schema_version' => 'payroll-production-qualification.v3',
            'parallel_runs' => $normalizedRuns,
            'correction_scenario' => [
                'payroll_run_id' => $correctionRunId,
                'period_start' => (string) $correctionRun['period_start'],
                'revision_id' => (int) $correctionRun['revision_id'],
                'approved_at' => (string) $correctionRun['approved_at'],
                ...$this->evidenceDocument(
                    $supplierId,
                    $correction['document_id'] ?? null,
                ),
            ],
            'recovery_drill' => [
                'completed_on' => $this->date($recovery['completed_on'] ?? null),
                ...$this->evidenceDocument(
                    $supplierId,
                    $recovery['document_id'] ?? null,
                ),
            ],
            'expert_approval' => [
                'approver_name' => $this->label(
                    $expertApproval['approver_name'] ?? null,
                    'Jméno odborného schvalovatele',
                ),
                'approver_role' => $this->label(
                    $expertApproval['approver_role'] ?? null,
                    'Role odborného schvalovatele',
                ),
                'approved_on' => $this->date($expertApproval['approved_on'] ?? null),
                ...$this->evidenceDocument(
                    $supplierId,
                    $expertApproval['document_id'] ?? null,
                ),
            ],
            'rollback_plan' => [
                'verified_on' => $this->date($rollbackPlan['verified_on'] ?? null),
                ...$this->evidenceDocument(
                    $supplierId,
                    $rollbackPlan['document_id'] ?? null,
                ),
            ],
            'post_go_live_monitoring' => [
                'prepared_on' => $this->date($monitoring['prepared_on'] ?? null),
                ...$this->evidenceDocument(
                    $supplierId,
                    $monitoring['document_id'] ?? null,
                ),
            ],
        ];
    }

    /** @return array{document_id:int,document_sha256:string} */
    private function evidenceDocument(int $supplierId, mixed $value): array
    {
        $documentId = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($documentId === false) {
            throw new PayrollProductionQualificationException(
                'evidence_document_required',
                'Každý kvalifikační důkaz musí odkazovat na firemní dokument v DMS.',
            );
        }

        $document = $this->documents->findActiveReferenceForUpdate(
            (int) $documentId,
            $supplierId,
            DocumentViewerContext::companyOnly(),
        );
        if ($document === null) {
            throw new PayrollProductionQualificationException(
                'company_evidence_document_required',
                'Kvalifikační důkaz musí být aktivní firemní dokument této firmy.',
            );
        }
        $sha256 = strtolower($document['sha256']);
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new \LogicException('Firemní dokument nemá platný SHA-256 otisk.');
        }

        return ['document_id' => $document['id'], 'document_sha256' => $sha256];
    }

    /**
     * @param array{
     *   parallel_runs:list<array{sequence:int,document_id:int,document_sha256:string}>,
     *   correction_scenario:array{document_id:int,document_sha256:string},
     *   recovery_drill:array{document_id:int,document_sha256:string},
     *   expert_approval:array{document_id:int,document_sha256:string},
     *   rollback_plan:array{document_id:int,document_sha256:string},
     *   post_go_live_monitoring:array{document_id:int,document_sha256:string}
     * } $evidence
     * @return list<array{evidence_key:string,sequence_no:int,document_id:int,document_sha256:string}>
     */
    private function evidenceDocuments(array $evidence): array
    {
        $documents = [];
        foreach ($evidence['parallel_runs'] as $run) {
            $documents[] = [
                'evidence_key' => 'parallel_run',
                'sequence_no' => $run['sequence'],
                'document_id' => $run['document_id'],
                'document_sha256' => $run['document_sha256'],
            ];
        }
        foreach ([
            'correction_scenario',
            'recovery_drill',
            'expert_approval',
            'rollback_plan',
            'post_go_live_monitoring',
        ] as $key) {
            $item = $evidence[$key];
            $documents[] = [
                'evidence_key' => $key,
                'sequence_no' => 1,
                'document_id' => $item['document_id'],
                'document_sha256' => $item['document_sha256'],
            ];
        }

        return $documents;
    }

    /** @return array<string,int|string> */
    private function approvedRun(int $supplierId, int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT run.period_start,
                    revision.id AS revision_id,
                    revision.revision_kind,
                    revision.status AS revision_status,
                    revision.approved_at
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
              WHERE run.supplier_id = ? AND run.id = ?'
        );
        $stmt->execute([$supplierId, $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new PayrollProductionQualificationException(
                'approved_run_required',
                'Kvalifikační doklad musí odkazovat na schválený běh této firmy.',
            );
        }
        $status = self::databaseString($row, 'revision_status');
        $approvedAt = $row['approved_at'] ?? null;
        if ($status !== self::APPROVED_REVISION_STATUS || !is_string($approvedAt)) {
            throw new PayrollProductionQualificationException(
                'approved_run_required',
                'Kvalifikační doklad musí odkazovat na schválený běh této firmy.',
            );
        }

        return [
            'period_start' => self::databaseString($row, 'period_start'),
            'revision_id' => self::databaseInt($row, 'revision_id'),
            'revision_kind' => self::databaseString($row, 'revision_kind'),
            'approved_at' => $approvedAt,
        ];
    }

    private function assertSupportedPeriod(string $periodStart): void
    {
        if (!$this->supportMatrix->supportsYear((int) substr($periodStart, 0, 4))) {
            throw new PayrollProductionQualificationException(
                'parallel_run_outside_support_matrix',
                'Kvalifikační běh leží mimo aktuální support matrix.',
            );
        }
    }

    private function positiveInt(mixed $value): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($int === false) {
            throw new PayrollProductionQualificationException(
                'invalid_payroll_run_id',
                'ID kvalifikačního běhu musí být kladné celé číslo.',
            );
        }

        return (int) $int;
    }

    private function label(mixed $value, string $fieldLabel): string
    {
        $label = is_string($value) ? trim($value) : '';
        if ($label === '' || mb_strlen($label) > 190) {
            throw new PayrollProductionQualificationException(
                'invalid_expert_approval',
                "{$fieldLabel} musí mít 1 až 190 znaků.",
            );
        }

        return $label;
    }

    private function date(mixed $value): string
    {
        $date = is_string($value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new PayrollProductionQualificationException(
                'invalid_recovery_date',
                'Datum recovery drillu musí mít formát YYYY-MM-DD.',
            );
        }

        return $value;
    }

    /** @param array<array-key,mixed> $row */
    private static function databaseInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new \LogicException("Databázové pole {$key} není celé číslo.");
    }

    /** @param array<array-key,mixed> $row */
    private static function databaseString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \LogicException("Databázové pole {$key} není řetězec.");
        }

        return $value;
    }

    private static function rollBackIfActive(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
