<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Service\Tax\Nonprofit\J6AuthoritativeCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;

final readonly class J6AssessmentReadService
{
    public function __construct(
        private NonprofitTaxPagedAssessmentRepository $assessments,
        private J6CurrentProviderFactory $current,
        private J6HistoricProviderFactory $historic,
        private NonprofitTaxSnapshotManifestRepository $snapshots,
        private NonprofitTaxSnapshotHasher $snapshotHasher,
        private J6CalculationPageHasher $calculationHasher,
        private J6SourceEvidencePageHasher $evidenceHasher,
        private J6AuthoritativeCalculator $calculator,
    ) {}

    public function currentSourceHeader(int $supplierId, int $periodId): array
    {
        return $this->current->fingerprint($supplierId, $periodId)->toArray();
    }

    public function periodSummary(int $supplierId, int $periodId): array
    {
        $head = $this->assessments->historyHeader($supplierId, $periodId);
        $latest = null;
        if ($head['assessment_id'] !== null) {
            if ($head['storage_version'] === 'paged_v2') {
                $latest = $this->summaryById($supplierId, $head['assessment_id']);
            } else {
                $latest = ['id' => $head['assessment_id'], 'supplier_id' => $supplierId,
                    'period_id' => $periodId, 'revision_no' => $head['revision_no'],
                    'previous_id' => $head['previous_id'], 'status' => $head['status'],
                    'reason' => $head['reason'], 'ruleset' => $head['ruleset'],
                    'storage_version' => $head['storage_version'], 'created_by' => $head['created_by'],
                    'created_at' => $head['created_at'], 'summary' => null,
                    'source_input_hash' => $head['source_input_hash'],
                    'snapshot_input_hash' => $head['source_input_hash'], 'receipt_hash' => null,
                    'conversion_required' => true];
            }
        }
        return ['source' => $this->currentSourceHeader($supplierId, $periodId), 'latest' => $latest];
    }

    public function currentSourcePage(int $supplierId, int $periodId, ?array $after = null, int $limit = 500): array
    {
        $page = $this->current->page($supplierId, $periodId, SnapshotCursor::fromArray($after), $limit);
        return ['rows' => $page->rows, 'findings' => $page->findings,
            'next_cursor' => $page->nextCursor?->toArray()];
    }

    public function summary(int $supplierId, int $periodId, int $assessmentId): array
    {
        return $this->view($this->owned($supplierId, $periodId, $assessmentId));
    }

    public function summaryById(int $supplierId, int $assessmentId): array
    {
        return $this->view($this->ownedById($supplierId, $assessmentId));
    }

    public function documentSummary(int $supplierId, int $periodId, int $assessmentId): array
    {
        $assessment = $this->owned($supplierId, $periodId, $assessmentId);
        $receipt = $assessment['graph_receipt'];
        $providers = $this->historic->create($this->assessments->binding($assessment));
        $sourceHeader = $providers->sources->header()->data;
        $decisionHeader = $providers->decisions->header();
        $head = $this->assessments->historyHeader($supplierId, $periodId);
        return ['schema' => 'myucto-nonprofit-tax-summary.v2',
            'scope' => 'classification_bridge_before_general_adjustments',
            'assessment' => $this->view($assessment),
            'period' => $sourceHeader['period'],
            'profile' => $decisionHeader['profile'] ?? null,
            'bridge' => $receipt->summary,
            'current_confirmed' => $head['assessment_id'] === $assessmentId && $head['status'] === 'confirmed',
            'full_evidence_format' => 'application/x-ndjson'];
    }

    public function history(int $supplierId, int $periodId, int $afterRevision = 0, int $limit = 100): array
    {
        $rows = $this->assessments->historyPage($supplierId, $periodId, $afterRevision, $limit);
        return ['rows' => array_map($this->view(...), $rows),
            'next_cursor' => count($rows) === $limit ? $rows[array_key_last($rows)]['revision_no'] : null];
    }

    public function historyHeader(int $supplierId, int $periodId): array
    {
        return $this->assessments->historyHeader($supplierId, $periodId);
    }

    public function calculationPage(int $supplierId, int $periodId, int $assessmentId, string $kind,
        int $afterOrdinal = -1): array
    {
        $assessment = $this->owned($supplierId, $periodId, $assessmentId);
        return $this->historic->create($this->assessments->binding($assessment))->calculation->page($kind, $afterOrdinal);
    }

    public function sourcePage(int $supplierId, int $periodId, int $assessmentId, int $afterOrdinal = -1): array
    {
        $assessment = $this->owned($supplierId, $periodId, $assessmentId);
        $receipt = $assessment['graph_receipt'];
        if ($afterOrdinal < -1) throw new \InvalidArgumentException('J6 source page cursor is invalid.');
        $manifest = $this->snapshots->manifest($supplierId, $receipt->snapshotManifestId);
        if ($manifest['period_id'] !== $periodId || !hash_equals($manifest['input_hash'], $receipt->snapshotInputHash)
            || !hash_equals($manifest['root_hash'], $receipt->snapshotRootHash)) {
            throw new \UnexpectedValueException('J6 source manifest belongs to another assessment.');
        }
        $chunks = $this->snapshots->chunkPage($supplierId, $receipt->snapshotManifestId, $afterOrdinal, 1);
        if ($chunks === []) return ['rows' => [], 'findings' => [], 'next_cursor' => null];
        $chunk = $chunks[0];
        if (!hash_equals((string) $chunk['link_hash'], (string) $chunk['chunk_hash'])) {
            throw new \UnexpectedValueException('J6 source chunk binding is invalid.');
        }
        $payload = json_decode($chunk['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        $this->snapshotHasher->verifyChunk($payload, (string) $chunk['chunk_hash']);
        $ordinal = (int) $chunk['ordinal'];
        return ['rows' => $payload['rows'], 'findings' => $payload['findings'],
            'next_cursor' => $ordinal + 1 < $receipt->snapshotChunkCount ? $ordinal : null];
    }

    public function export(int $supplierId, int $periodId, int $assessmentId): iterable
    {
        $assessment = $this->owned($supplierId, $periodId, $assessmentId);
        $providers = $this->historic->create($this->assessments->binding($assessment));
        yield ['kind' => 'header', 'payload' => $this->view($assessment)];
        foreach ($providers->sources->pages(null, 500) as $page) {
            yield ['kind' => 'source', 'rows' => $page->rows, 'findings' => $page->findings];
        }
        foreach (['decision', 'result', 'coverage', 'usage'] as $kind) {
            foreach ($providers->calculation->pages($kind, 500) as $rows) yield ['kind' => $kind, 'rows' => $rows];
        }
        foreach ($providers->sources->fundingReferencePages(500) as $rows) yield ['kind' => 'funding_reference', 'rows' => $rows];
        foreach ($providers->sources->fundingHistoryPages(500) as $rows) yield ['kind' => 'funding_history', 'rows' => $rows];
    }

    public function verify(int $supplierId, int $periodId, int $assessmentId,
        J6ReadMode $mode = J6ReadMode::HISTORIC): J6CalculationReceipt
    {
        $assessment = $this->owned($supplierId, $periodId, $assessmentId);
        $receipt = $assessment['graph_receipt'];
        $providers = $this->historic->create($this->assessments->binding($assessment), $mode);
        return $this->calculator->calculate($providers->sources, $providers->decisions,
            new J6VerificationSink($this->calculationHasher, $this->evidenceHasher, $receipt));
    }

    private function owned(int $supplierId, int $periodId, int $assessmentId): array
    {
        $assessment = $this->ownedById($supplierId, $assessmentId);
        if ($assessment['period_id'] !== $periodId) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_assessment_not_found', 'Daňové posouzení nebylo nalezeno.', 404);
        }
        return $assessment;
    }

    private function ownedById(int $supplierId, int $assessmentId): array
    {
        $assessment = $this->assessments->find($supplierId, $assessmentId);
        $this->assessments->binding($assessment);
        return $assessment;
    }

    private function view(array $assessment): array
    {
        $receipt = $assessment['graph_receipt'];
        $view = ['id' => $assessment['id'], 'supplier_id' => $assessment['supplier_id'],
            'period_id' => $assessment['period_id'], 'revision_no' => $assessment['revision_no'],
            'previous_id' => $assessment['previous_id'], 'status' => $assessment['status'],
            'reason' => $assessment['reason'], 'ruleset' => $assessment['ruleset'],
            'storage_version' => $assessment['storage_version'], 'created_by' => $assessment['created_by'],
            'created_at' => $assessment['created_at'], 'summary' => $receipt?->summary,
            'source_input_hash' => $receipt?->sourceInputHash,
            'snapshot_input_hash' => $receipt?->snapshotInputHash,
            'receipt_hash' => $receipt?->toArray()['receipt_hash']];
        return $view;
    }
}
