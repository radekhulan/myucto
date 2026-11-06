<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxDraftRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;

final readonly class J6AssessmentDraftQueryService
{
    public function __construct(
        private NonprofitTaxDraftRepository $drafts,
        private NonprofitTaxSnapshotManifestRepository $snapshots,
        private NonprofitTaxSnapshotHasher $snapshotHasher,
    ) {}

    public function get(int $supplierId, int $periodId, int $draftId, int $actor): array
    {
        return $this->view($this->owned($supplierId, $periodId, $draftId, $actor));
    }

    public function current(int $supplierId, int $periodId, int $actor): ?array
    {
        $draft = $this->drafts->ownedByActor($supplierId, $periodId, $actor);
        return $draft === null ? null : $this->view($draft);
    }

    public function sourcePage(J6DraftContext $context, int $afterOrdinal = -1): array
    {
        if ($afterOrdinal < -1) throw new \InvalidArgumentException('J6 source page cursor is invalid.');
        if ($this->snapshots === null || $this->snapshotHasher === null) throw new \LogicException('J6 draft source paging is not configured.');
        $draft = $this->exact($context);
        $manifestId = $draft['source_manifest_id'] ?? null;
        if (!is_int($manifestId) || $manifestId <= 0) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_source_not_captured', 'Koncept nemá neměnný stránkovaný zdroj.', 409);
        }
        $manifest = $this->snapshots->manifest($context->supplierId, $manifestId);
        if ($manifest['period_id'] !== $context->periodId || !hash_equals($manifest['input_hash'], $context->sourceInputHash)) {
            throw new \UnexpectedValueException('J6 draft source manifest binding is invalid.');
        }
        $chunks = $this->snapshots->chunkPage($context->supplierId, $manifestId, $afterOrdinal, 1);
        if ($chunks === []) return ['draft' => $this->view($draft), 'rows' => [], 'findings' => [], 'next_cursor' => null];
        $chunk = $chunks[0];
        if (!hash_equals((string) $chunk['link_hash'], (string) $chunk['chunk_hash'])) {
            throw new \UnexpectedValueException('J6 draft source chunk binding is invalid.');
        }
        $payload = json_decode($chunk['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        $this->snapshotHasher->verifyChunk($payload, (string) $chunk['chunk_hash']);
        $ordinal = (int) $chunk['ordinal'];
        return ['draft' => $this->view($draft), 'rows' => $payload['rows'], 'findings' => $payload['findings'],
            'next_cursor' => $ordinal + 1 < (int) $manifest['chunk_count'] ? $ordinal : null];
    }

    public function sourceSummary(J6DraftContext $context): array
    {
        $draft = $this->exact($context);
        $manifestId = $draft['source_manifest_id'] ?? null;
        if (!is_int($manifestId) || $manifestId <= 0) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_source_not_captured', 'Koncept nemá neměnný stránkovaný zdroj.', 409);
        }
        $manifest = $this->snapshots->manifest($context->supplierId, $manifestId);
        if ($manifest['period_id'] !== $context->periodId || !hash_equals($manifest['input_hash'], $context->sourceInputHash)) {
            throw new \UnexpectedValueException('J6 draft source manifest binding is invalid.');
        }
        return ['source_manifest_id' => $manifest['id'], 'input_hash' => $manifest['input_hash'],
            'row_count' => $manifest['row_count'], 'finding_count' => $manifest['finding_count'],
            'chunk_count' => $manifest['chunk_count'], 'source_complete' => $manifest['finding_count'] === 0];
    }

    public function decisionPage(J6DraftContext $context, ?string $after = null, int $limit = 500): array
    {
        return $this->page($context, $this->drafts->page($context->supplierId, $context->draftId, 'decision', $after, $limit),
            static fn (array $row): string => $row['source_id'], $limit);
    }

    public function activityPage(J6DraftContext $context, ?string $after = null, int $limit = 500): array
    {
        return $this->page($context, $this->drafts->page($context->supplierId, $context->draftId, 'activity', $after, $limit),
            static fn (array $row): string => $row['activity_kind_id'], $limit);
    }

    public function fundingPoolPage(J6DraftContext $context, ?array $after = null, int $limit = 500): array
    {
        return $this->page($context, $this->drafts->fundingPoolPage($context->supplierId, $context->draftId, $after, $limit),
            static fn (array $row): array => ['pool_kind' => $row['pool_kind'], 'anchor' => $row['anchor']], $limit);
    }

    public function fundingMemberPoolPage(J6DraftContext $context, string $poolKind, string $anchor,
        ?string $after = null, int $limit = 500): array
    {
        return $this->page($context, $this->drafts->fundingMemberPoolPage($context->supplierId, $context->draftId,
            $poolKind, $anchor, $after, $limit), static fn (array $row): string => $row['member_id'], $limit);
    }
    public function fundingMemberPage(J6DraftContext $context, ?array $after = null, int $limit = 500): array
    {
        return $this->page($context, $this->drafts->fundingMemberPage($context->supplierId, $context->draftId, $after, $limit),
            static fn (array $row): array => ['pool_kind' => $row['pool_kind'], 'anchor' => $row['anchor'],
                'member_id' => $row['member_id']], $limit);
    }

    public function fundingLinkPage(J6DraftContext $context, ?array $after = null, int $limit = 500): array
    {
        return $this->page($context, $this->drafts->fundingLinkPage($context->supplierId, $context->draftId, $after, $limit),
            static fn (array $row): array => ['source_id' => $row['source_id'], 'cost_id' => $row['cost_id']], $limit);
    }

    private function page(J6DraftContext $context, array $rows, callable $cursor, int $limit): array
    {
        $draft = $this->exact($context);
        return ['draft' => $this->view($draft), 'rows' => $rows,
            'next_cursor' => count($rows) === $limit ? $cursor($rows[array_key_last($rows)]) : null];
    }

    private function exact(J6DraftContext $context): array
    {
        $draft = $this->owned($context->supplierId, $context->periodId, $context->draftId, $context->actor);
        if ($draft['revision_no'] !== $context->revision
            || !hash_equals($draft['source_input_hash'], $context->sourceInputHash)) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_changed',
                'Koncept posouzení nebo jeho účetní podklad se změnil.', 409);
        }
        return $draft;
    }

    private function owned(int $supplierId, int $periodId, int $draftId, int $actor): array
    {
        if ($supplierId <= 0 || $periodId <= 0 || $draftId <= 0 || $actor <= 0) {
            throw new \InvalidArgumentException('Draft read identity is invalid.');
        }
        $draft = $this->drafts->find($supplierId, $draftId);
        if ($draft['period_id'] !== $periodId || $draft['created_by'] !== $actor) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_not_found',
                'Koncept daňového posouzení nebyl nalezen.', 404);
        }
        return $draft;
    }

    private function view(array $draft): array
    {
        return array_intersect_key($draft, array_flip(['id', 'supplier_id', 'period_id', 'revision_no',
            'source_manifest_id', 'source_input_hash', 'header', 'created_by', 'created_at', 'updated_at']));
    }
}


