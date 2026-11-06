<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;

final readonly class HistoricJ6FundingEvidenceProvider implements J6FundingEvidenceProvider
{
    public function __construct(
        private NonprofitTaxSourceEvidenceManifestRepository $manifests,
        private J6SourceEvidencePageHasher $hasher,
        private J6HistoricAssessmentBinding $binding,
    ) {}

    public function fundingReferencePages(J6ReadContext $context, int $limit = 500): iterable
    {
        yield from $this->pages($context, 'funding_reference', $limit);
    }

    public function fundingHistoryPages(J6ReadContext $context, int $limit = 500): iterable
    {
        yield from $this->pages($context, 'funding_history', $limit);
    }

    private function pages(J6ReadContext $context, string $kind, int $limit): iterable
    {
        if ($context->mode === J6ReadMode::CURRENT || $context->supplierId !== $this->binding->supplierId
            || $context->periodId !== $this->binding->periodId || $context->assessmentId !== $this->binding->assessmentId
            || $limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Historic J6 source evidence context is invalid.');
        }
        $manifestId = $this->binding->evidenceManifestIds()[$kind];
        $manifest = $this->manifests->manifest($context->supplierId, $manifestId);
        if ($manifest['period_id'] !== $context->periodId || $manifest['evidence_kind'] !== $kind
            || !hash_equals((string) $manifest['source_input_hash'], $this->binding->sourceInputHash)
            || !hash_equals((string) $manifest['root_hash'], $this->binding->evidenceRootHashes[$kind])
            || ($manifest['header']['snapshot_input_hash'] ?? null) !== $this->binding->snapshotInputHash
            || ($manifest['header']['source_manifest_id'] ?? null) !== $this->binding->sourceManifestId) {
            throw new \UnexpectedValueException('Historic J6 source evidence manifest belongs to another assessment.');
        }
        $ordinal = -1;
        $buffer = [];
        do {
            $chunks = $this->manifests->chunkPage($context->supplierId, $manifestId, $ordinal, 4);
            foreach ($chunks as $chunk) {
                if ($chunk['evidence_kind'] !== $kind || $chunk['schema_version'] !== J6SourceEvidencePageHasher::SCHEMA
                    || !hash_equals((string) $chunk['link_hash'], (string) $chunk['chunk_hash'])) {
                    throw new \UnexpectedValueException('Historic J6 source evidence chunk binding is invalid.');
                }
                $payload = json_decode($chunk['payload_json'], true, flags: JSON_THROW_ON_ERROR);
                $this->hasher->verify($payload, (string) $chunk['chunk_hash']);
                $ordinal = (int) $chunk['ordinal'];
                foreach ($payload['rows'] as $row) {
                    $buffer[] = $row;
                    if (count($buffer) === $limit) {
                        yield $buffer;
                        $buffer = [];
                    }
                }
            }
        } while (count($chunks) === 4);
        if ($buffer !== []) yield $buffer;
    }
}
