<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotManifestReference;

final readonly class J6AssessmentGraphReceipt
{
    public const string SCHEMA = 'nonprofit-j6-assessment-graph.v1';

    public function __construct(
        public string $ruleset,
        public int $snapshotManifestId,
        public string $snapshotInputHash,
        public string $snapshotRootHash,
        public int $snapshotRowCount,
        public int $snapshotFindingCount,
        public int $snapshotChunkCount,
        public string $sourceInputHash,
        public string $decisionInputHash,
        public string $calculationInputHash,
        public array $manifestIds,
        public array $rootHashes,
        public array $counts,
        public array $evidenceManifestIds,
        public array $evidenceRootHashes,
        public array $evidenceCounts,
        public array $summary,
    ) {
        if ($ruleset === '' || $snapshotManifestId <= 0 || $snapshotRowCount < 0 || $snapshotFindingCount < 0 || $snapshotChunkCount < 0) {
            throw new \InvalidArgumentException('J6 assessment graph snapshot receipt is invalid.');
        }
        foreach ([$snapshotInputHash, $snapshotRootHash, $sourceInputHash, $decisionInputHash, $calculationInputHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) throw new \InvalidArgumentException('J6 assessment graph hash is invalid.');
        }
        foreach (['decision', 'result', 'coverage', 'usage'] as $kind) {
            self::manifest($manifestIds, $rootHashes, $counts, $kind);
        }
        foreach (['funding_reference', 'funding_history'] as $kind) {
            self::manifest($evidenceManifestIds, $evidenceRootHashes, $evidenceCounts, $kind);
        }
        foreach (['source_row_count', 'decision_record_count', 'funding_usage_count', 'accounting_result_cents',
            'excluded_revenue_cents', 'cost_addbacks_cents', 'assessed_result_cents'] as $field) {
            if (!is_int($summary[$field] ?? null)) throw new \InvalidArgumentException('J6 assessment graph summary is incomplete.');
        }
        if ($summary['source_row_count'] !== $snapshotRowCount || $summary['source_row_count'] !== $counts['result']
            || $summary['source_row_count'] !== $counts['coverage'] || $summary['decision_record_count'] !== $counts['decision']
            || $summary['funding_usage_count'] !== $counts['usage']) {
            throw new \InvalidArgumentException('J6 assessment graph totals do not reconcile.');
        }
    }

    public static function fromCalculation(SnapshotManifestReference $snapshot, int $findingCount, J6CalculationReceipt $calculation): self
    {
        $header = $calculation->header;
        if (($header->snapshotInputHash ?? $header->sourceInputHash) !== $snapshot->inputHash
            || $header->sourceManifestId !== $snapshot->id) {
            throw new \UnexpectedValueException('J6 calculation is not bound to the frozen snapshot.');
        }
        return new self($header->ruleset, $snapshot->id, $snapshot->inputHash, $snapshot->rootHash,
            $snapshot->rowCount, $findingCount, $snapshot->chunkCount, $header->sourceInputHash,
            $header->decisionInputHash, $header->calculationInputHash, $calculation->manifestIds,
            $calculation->rootHashes, $calculation->counts, $calculation->evidenceManifestIds,
            $calculation->evidenceRootHashes, $calculation->evidenceCounts, $calculation->summary);
    }

    public static function fromArray(array $value): self
    {
        if (($value['schema'] ?? null) !== self::SCHEMA) throw new \UnexpectedValueException('J6 assessment graph schema is unsupported.');
        $receipt = new self((string) ($value['ruleset'] ?? ''), (int) ($value['snapshot']['manifest_id'] ?? 0),
            (string) ($value['snapshot']['input_hash'] ?? ''), (string) ($value['snapshot']['root_hash'] ?? ''),
            (int) ($value['snapshot']['row_count'] ?? -1), (int) ($value['snapshot']['finding_count'] ?? -1),
            (int) ($value['snapshot']['chunk_count'] ?? -1), (string) ($value['inputs']['source_hash'] ?? ''),
            (string) ($value['inputs']['decision_hash'] ?? ''), (string) ($value['inputs']['calculation_hash'] ?? ''),
            (array) ($value['calculation']['manifest_ids'] ?? []), (array) ($value['calculation']['root_hashes'] ?? []),
            (array) ($value['calculation']['counts'] ?? []), (array) ($value['evidence']['manifest_ids'] ?? []),
            (array) ($value['evidence']['root_hashes'] ?? []), (array) ($value['evidence']['counts'] ?? []),
            (array) ($value['summary'] ?? []));
        $receipt->verifyReceiptHash($value);
        return $receipt;
    }

    public function toArray(): array
    {
        return ['schema' => self::SCHEMA, 'ruleset' => $this->ruleset,
            'snapshot' => ['manifest_id' => $this->snapshotManifestId, 'input_hash' => $this->snapshotInputHash,
                'root_hash' => $this->snapshotRootHash, 'row_count' => $this->snapshotRowCount,
                'finding_count' => $this->snapshotFindingCount, 'chunk_count' => $this->snapshotChunkCount],
            'inputs' => ['source_hash' => $this->sourceInputHash, 'decision_hash' => $this->decisionInputHash,
                'calculation_hash' => $this->calculationInputHash],
            'calculation' => ['manifest_ids' => $this->manifestIds, 'root_hashes' => $this->rootHashes, 'counts' => $this->counts],
            'evidence' => ['manifest_ids' => $this->evidenceManifestIds, 'root_hashes' => $this->evidenceRootHashes,
                'counts' => $this->evidenceCounts], 'summary' => $this->summary,
            'receipt_hash' => $this->hashPayload()];
    }

    public function verifyReceiptHash(array $stored): void
    {
        if (!hash_equals((string) ($stored['receipt_hash'] ?? ''), $this->hashPayload())) {
            throw new \UnexpectedValueException('J6 assessment graph receipt hash is invalid.');
        }
    }

    private function hashPayload(): string
    {
        return NonprofitTaxRules::hash(['domain' => 'J6ASSESSMENTGRAPH1', 'ruleset' => $this->ruleset,
            'snapshot' => [$this->snapshotManifestId, $this->snapshotInputHash, $this->snapshotRootHash,
                $this->snapshotRowCount, $this->snapshotFindingCount, $this->snapshotChunkCount],
            'inputs' => [$this->sourceInputHash, $this->decisionInputHash, $this->calculationInputHash],
            'manifests' => $this->manifestIds, 'roots' => $this->rootHashes, 'counts' => $this->counts,
            'evidence_manifests' => $this->evidenceManifestIds, 'evidence_roots' => $this->evidenceRootHashes,
            'evidence_counts' => $this->evidenceCounts, 'summary' => $this->summary]);
    }

    private static function manifest(array $ids, array $roots, array $counts, string $kind): void
    {
        if (!is_int($ids[$kind] ?? null) || $ids[$kind] <= 0
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($roots[$kind] ?? '')) !== 1
            || !is_int($counts[$kind] ?? null) || $counts[$kind] < 0) {
            throw new \InvalidArgumentException('J6 assessment graph manifest receipt is incomplete.');
        }
    }
}
