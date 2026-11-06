<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotBatchReader;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use PDO;

final readonly class J6CurrentFundingEvidenceProvider implements J6FundingEvidenceProvider
{
    public function __construct(
        private Connection $db,
        private NonprofitTaxPagedAssessmentRepository $assessments,
        private J6HistoricProviderFactory $historic,
        private NonprofitTaxSnapshotBatchReader $reader,
        private NonprofitTaxSnapshotHasher $hasher,
    ) {}

    public function fundingReferencePages(J6ReadContext $context, int $limit = 500): iterable
    {
        $this->context($context, $limit);
        $page = [];
        foreach ($this->history($context) as [$assessment, $current]) {
            $providers = $this->historic->create($this->assessments->binding($assessment), J6ReadMode::HISTORIC);
            foreach ($providers->calculation->pages('result', $limit) as $rows) {
                foreach ($rows as $row) {
                    if (($row['kind'] ?? null) !== 'revenue'
                        || !in_array($row['decision']['title'] ?? null,
                            ['gift', 'public_operating_grant', 'public_investment_grant'], true)) continue;
                    $row['reference_assessment_id'] = $assessment['id'];
                    $row['reference_context_current'] = $current;
                    $row['reference_current'] = $current;
                    $page[] = $row;
                    if (count($page) === $limit) {
                        yield $page;
                        $page = [];
                    }
                }
            }
        }
        if ($page !== []) yield $page;
    }

    public function fundingHistoryPages(J6ReadContext $context, int $limit = 500): iterable
    {
        $this->context($context, $limit);
        $page = [];
        foreach ($this->history($context) as [$assessment, $current]) {
            $providers = $this->historic->create($this->assessments->binding($assessment), J6ReadMode::HISTORIC);
            foreach ($providers->calculation->pages('usage', $limit) as $rows) {
                foreach ($rows as $row) {
                    $row['assessment_id'] = $assessment['id'];
                    $row['period_id'] = $assessment['period_id'];
                    $row['status'] = $assessment['status'];
                    $row['current'] = $current;
                    $page[] = $row;
                    if (count($page) === $limit) {
                        yield $page;
                        $page = [];
                    }
                }
            }
        }
        if ($page !== []) yield $page;
    }

    private function history(J6ReadContext $context): iterable
    {
        $stmt = $this->db->pdo()->prepare('SELECT starts_on FROM accounting_periods WHERE supplier_id=? AND id=? FOR UPDATE');
        $stmt->execute([$context->supplierId, $context->periodId]);
        $startsOn = $stmt->fetchColumn();
        if (!is_string($startsOn)) throw new \OutOfBoundsException('J6 funding period was not found.');
        $after = 0;
        do {
            $rows = $this->assessments->effectiveBeforePage($context->supplierId, $context->periodId, $startsOn, $after, 100, true);
            foreach ($rows as $assessment) {
                $after = $assessment['period_id'];
                if ($assessment['status'] !== 'confirmed') continue;
                $receipt = $assessment['graph_receipt'];
                if (!$receipt instanceof J6AssessmentGraphReceipt) $this->assessments->binding($assessment);
                $current = hash_equals($receipt->snapshotInputHash,
                    $this->snapshotInputHash($context->supplierId, $assessment['period_id']));
                yield [$assessment, $current];
            }
        } while (count($rows) === 100);
    }

    private function snapshotInputHash(int $supplierId, int $periodId): string
    {
        $header = $this->reader->header($supplierId, $periodId, true);
        $root = $this->hasher->startRoot();
        $cursor = null;
        $ordinal = $rows = $findings = 0;
        do {
            $page = $this->reader->page($supplierId, $periodId, $cursor, 500, true);
            if ($page->rows !== [] || $page->findings !== []) {
                $chunk = $this->hasher->chunk($ordinal, $page);
                $this->hasher->updateRoot($root, $chunk['descriptor']);
                $rows += $chunk['descriptor']['row_count'];
                $findings += $chunk['descriptor']['finding_count'];
                ++$ordinal;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return $this->hasher->input($header, $this->hasher->header($header), $this->hasher->finishRoot($root, $ordinal),
            $rows, $findings, $ordinal);
    }

    private function context(J6ReadContext $context, int $limit): void
    {
        if (!in_array($context->mode, [J6ReadMode::CURRENT, J6ReadMode::FROZEN_CURRENT], true)
            || !$context->currentRead || !$this->db->pdo()->inTransaction()
            || $limit < 1 || $limit > 500) {
            throw new \LogicException('Current J6 funding evidence requires a locked owner transaction.');
        }
    }
}
