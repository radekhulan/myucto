<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class J6VerificationSink implements J6CalculationSink
{
    private ?J6CalculationHeader $header = null;
    private array $roots = [];
    private array $counts = [];
    private array $chunks = [];
    private bool $closed = false;

    public function __construct(
        private readonly J6CalculationPageHasher $hasher,
        private readonly J6SourceEvidencePageHasher $evidenceHasher,
        private readonly J6AssessmentGraphReceipt $expected,
    ) {}

    public function begin(J6CalculationHeader $header): void
    {
        if ($this->header !== null || $this->closed) throw new \LogicException('J6 verification sink was already started.');
        if (!hash_equals($header->snapshotInputHash ?? $header->sourceInputHash, $this->expected->snapshotInputHash)
            || !hash_equals($header->sourceInputHash, $this->expected->sourceInputHash)
            || !hash_equals($header->decisionInputHash, $this->expected->decisionInputHash)
            || !hash_equals($header->calculationInputHash, $this->expected->calculationInputHash)
            || $header->sourceManifestId !== $this->expected->snapshotManifestId) {
            throw new \UnexpectedValueException('J6 verification header changed.');
        }
        $this->header = $header;
        foreach (['decision', 'result', 'coverage', 'usage', 'funding_reference', 'funding_history'] as $kind) {
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
        $roots = $evidenceRoots = [];
        foreach (['decision', 'result', 'coverage', 'usage'] as $kind) {
            $roots[$kind] = $this->hasher->finishRoot($this->roots[$kind], $this->chunks[$kind], $this->counts[$kind]);
        }
        foreach (['funding_reference', 'funding_history'] as $kind) {
            $evidenceRoots[$kind] = $this->evidenceHasher->finishRoot($this->roots[$kind], $this->chunks[$kind], $this->counts[$kind]);
        }
        $counts = array_intersect_key($this->counts, $roots);
        $evidenceCounts = array_intersect_key($this->counts, $evidenceRoots);
        if (!hash_equals(NonprofitTaxRules::canonicalJson($roots), NonprofitTaxRules::canonicalJson($this->expected->rootHashes))
            || !hash_equals(NonprofitTaxRules::canonicalJson($counts), NonprofitTaxRules::canonicalJson($this->expected->counts))
            || !hash_equals(NonprofitTaxRules::canonicalJson($evidenceRoots), NonprofitTaxRules::canonicalJson($this->expected->evidenceRootHashes))
            || !hash_equals(NonprofitTaxRules::canonicalJson($evidenceCounts), NonprofitTaxRules::canonicalJson($this->expected->evidenceCounts))
            || !hash_equals(NonprofitTaxRules::canonicalJson($summary), NonprofitTaxRules::canonicalJson($this->expected->summary))) {
            throw new \UnexpectedValueException('J6 historic calculation does not match its immutable receipt.');
        }
        $this->closed = true;
        return new J6CalculationReceipt($header, $this->expected->manifestIds, $roots, $counts, $summary, null,
            $this->expected->evidenceManifestIds, $evidenceRoots, $evidenceCounts);
    }

    public function abort(): void
    {
        $this->closed = true;
    }

    private function append(string $kind, array $rows): void
    {
        $this->requireOpen();
        if ($rows === []) return;
        $chunk = $this->evidence($kind) ? $this->evidenceHasher->page($kind, $this->chunks[$kind], $rows)
            : $this->hasher->page($kind, $this->chunks[$kind], $rows);
        if ($this->evidence($kind)) $this->evidenceHasher->updateRoot($this->roots[$kind], $chunk['descriptor']);
        else $this->hasher->updateRoot($this->roots[$kind], $chunk['descriptor']);
        $this->counts[$kind] += $chunk['descriptor']['row_count'];
        ++$this->chunks[$kind];
    }

    private function requireOpen(): J6CalculationHeader
    {
        if ($this->header === null || $this->closed) throw new \LogicException('J6 verification sink is not open.');
        return $this->header;
    }

    private function evidence(string $kind): bool
    {
        return in_array($kind, ['funding_reference', 'funding_history'], true);
    }
}
