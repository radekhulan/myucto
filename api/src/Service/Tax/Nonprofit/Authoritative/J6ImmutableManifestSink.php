<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;

final class J6ImmutableManifestSink implements J6CalculationSink
{
    private ?J6CalculationHeader $header = null;
    private array $spools = [];
    private array $roots = [];
    private array $counts = [];
    private array $chunks = [];
    private bool $closed = false;

    public function __construct(
        private readonly NonprofitTaxCalculationManifestRepository $manifests,
        private readonly J6CalculationPageHasher $hasher,
        private readonly NonprofitTaxSourceEvidenceManifestRepository $evidenceManifests,
        private readonly J6SourceEvidencePageHasher $evidenceHasher,
    ) {}

    public function begin(J6CalculationHeader $header): void
    {
        if ($this->header !== null || $this->closed) throw new \LogicException('J6 calculation sink was already started.');
        $this->header = $header;
        $token = bin2hex(random_bytes(16));
        foreach (['decision', 'result', 'coverage', 'usage', 'funding_reference', 'funding_history'] as $kind) {
            $this->spools[$kind] = new J6CalculationChunkSpool($token, $kind);
            $this->roots[$kind] = $this->evidence($kind)
                ? $this->evidenceHasher->startRoot($header, $kind) : $this->hasher->startRoot($header, $kind);
            $this->counts[$kind] = 0;
            $this->chunks[$kind] = 0;
        }
    }

    public function appendDecisionPage(array $rows): void { $this->append('decision', $rows); }
    public function appendResultPage(array $rows): void { $this->append('result', $rows); }
    public function appendCoveragePage(array $rows): void { $this->append('coverage', $rows); }
    public function appendFundingUsagePage(array $rows): void { $this->append('usage', $rows); }
    public function appendFundingReferencePage(array $rows): void { $this->append('funding_reference', $rows); }
    public function appendFundingHistoryPage(array $rows): void { $this->append('funding_history', $rows); }

    public function complete(array $summary): J6CalculationReceipt
    {
        $header = $this->requireOpen();
        try {
            $this->assertSummary($summary);
            $rootHashes = $manifestIds = $evidenceRootHashes = $evidenceManifestIds = [];
            foreach (['decision', 'result', 'coverage', 'usage'] as $kind) {
                $rootHashes[$kind] = $this->hasher->finishRoot($this->roots[$kind], $this->chunks[$kind], $this->counts[$kind]);
                $manifestIds[$kind] = $this->manifests->appendManifest($header, $kind, $rootHashes[$kind], $this->counts[$kind],
                    $this->chunks[$kind], $summary, $this->spools[$kind]->records());
            }
            foreach (['funding_reference', 'funding_history'] as $kind) {
                $evidenceRootHashes[$kind] = $this->evidenceHasher->finishRoot($this->roots[$kind], $this->chunks[$kind], $this->counts[$kind]);
                $evidenceManifestIds[$kind] = $this->evidenceManifests->appendManifest($header, $kind,
                    $evidenceRootHashes[$kind], $this->counts[$kind], $this->chunks[$kind], $this->spools[$kind]->records());
            }
            $receipt = new J6CalculationReceipt($header, $manifestIds, $rootHashes,
                array_intersect_key($this->counts, array_flip(['decision', 'result', 'coverage', 'usage'])), $summary, null,
                $evidenceManifestIds, $evidenceRootHashes,
                array_intersect_key($this->counts, array_flip(['funding_reference', 'funding_history'])));
            $this->closeSpools();
            $this->closed = true;
            return $receipt;
        } catch (\Throwable $failure) {
            $this->abort();
            throw $failure;
        }
    }

    public function abort(): void
    {
        if ($this->closed) return;
        $this->closeSpools();
        $this->closed = true;
    }

    public function __destruct()
    {
        if (!$this->closed) $this->abort();
    }

    private function append(string $kind, array $rows): void
    {
        $header = $this->requireOpen();
        if ($rows === []) return;
        $evidence = $this->evidence($kind);
        $chunk = $evidence ? $this->evidenceHasher->page($kind, $this->chunks[$kind], $rows)
            : $this->hasher->page($kind, $this->chunks[$kind], $rows);
        $chunkId = $evidence ? $this->evidenceManifests->putChunk($header->supplierId, $kind, $chunk)
            : $this->manifests->putChunk($header->supplierId, $kind, $chunk);
        if ($evidence) $this->evidenceHasher->updateRoot($this->roots[$kind], $chunk['descriptor']);
        else $this->hasher->updateRoot($this->roots[$kind], $chunk['descriptor']);
        $this->spools[$kind]->append($chunkId, $chunk['descriptor']);
        $this->counts[$kind] += $chunk['descriptor']['row_count'];
        ++$this->chunks[$kind];
    }

    private function assertSummary(array $summary): void
    {
        $sourceCount = $summary['source_row_count'] ?? null;
        if (!is_int($sourceCount) || $sourceCount < 0 || ($summary['decision_record_count'] ?? null) !== $this->counts['decision']
            || $sourceCount !== $this->counts['result'] || $sourceCount !== $this->counts['coverage']
            || ($summary['funding_usage_count'] ?? null) !== $this->counts['usage']) {
            throw new \UnexpectedValueException('J6 calculation rows do not reconcile.');
        }
        foreach (['accounting_result_cents', 'excluded_revenue_cents', 'cost_addbacks_cents', 'assessed_result_cents'] as $field) {
            if (!is_int($summary[$field] ?? null)) throw new \UnexpectedValueException('J6 calculation summary is incomplete.');
        }
    }

    private function requireOpen(): J6CalculationHeader
    {
        if ($this->header === null || $this->closed) throw new \LogicException('J6 calculation sink is not open.');
        return $this->header;
    }

    private function closeSpools(): void
    {
        foreach ($this->spools as $spool) $spool->closeAndDelete();
        $this->spools = [];
    }

    private function evidence(string $kind): bool
    {
        return in_array($kind, ['funding_reference', 'funding_history'], true);
    }
}
