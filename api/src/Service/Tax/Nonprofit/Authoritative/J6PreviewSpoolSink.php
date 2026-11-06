<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class J6PreviewSpoolSink implements J6CalculationSink
{
    private readonly string $token;
    private readonly string $work;
    private ?J6CalculationHeader $header = null;
    private array $roots = [];
    private array $counts = [];
    private array $chunks = [];
    private bool $closed = false;

    public function __construct(
        private readonly J6PreviewCache $cache,
        CanonicalJsonSpool $canonical,
        private readonly J6PreviewIdentity $identity,
        private readonly int $createdAt,
    ) {
        $this->hasher = new J6CalculationPageHasher($canonical);
        $this->evidenceHasher = new J6SourceEvidencePageHasher($canonical);
        $this->token = bin2hex(random_bytes(32));
        $this->work = $cache->base() . DIRECTORY_SEPARATOR . 'work-' . $this->token;
        $cache->assertChild($cache->base(), $this->work, false);
        if (!mkdir($this->work, 0700)) throw new \RuntimeException('J6 preview workspace cannot be created.');
        $this->write('.j6-preview-owner', ['token' => $this->token, 'created_at' => $createdAt]);
    }

    private readonly J6CalculationPageHasher $hasher;
    private readonly J6SourceEvidencePageHasher $evidenceHasher;

    public function begin(J6CalculationHeader $header): void
    {
        if ($this->header !== null || $this->closed) throw new \LogicException('J6 preview sink was already started.');
        if ($header->supplierId !== $this->identity->supplierId || $header->periodId !== $this->identity->periodId
            || !hash_equals((string) ($header->decisionHeader['source_input_hash'] ?? ''), $this->identity->inputHash)
            || ($header->decisionHeader['draft_id'] ?? null) !== $this->identity->draftId
            || ($header->decisionHeader['draft_revision'] ?? null) !== $this->identity->draftRevision) {
            throw new \UnexpectedValueException('J6 preview identity does not match calculation.');
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
        try {
            $sourceCount = $summary['source_row_count'] ?? null;
            if (!is_int($sourceCount) || ($summary['decision_record_count'] ?? null) !== $this->counts['decision'] || $sourceCount !== $this->counts['result']
                || $sourceCount !== $this->counts['coverage'] || ($summary['funding_usage_count'] ?? null) !== $this->counts['usage']) {
                throw new \UnexpectedValueException('J6 preview rows do not reconcile.');
            }
            $roots = $evidenceRoots = [];
            foreach (['decision', 'result', 'coverage', 'usage'] as $kind) {
                $roots[$kind] = $this->hasher->finishRoot($this->roots[$kind], $this->chunks[$kind], $this->counts[$kind]);
            }
            foreach (['funding_reference', 'funding_history'] as $kind) {
                $evidenceRoots[$kind] = $this->evidenceHasher->finishRoot($this->roots[$kind], $this->chunks[$kind], $this->counts[$kind]);
            }
            $metadata = [
                'schema' => 'nonprofit-j6-preview.v1',
                'token' => $this->token,
                'identity' => $this->identity->toArray(),
                'header' => $header->toArray(),
                'created_at' => $this->createdAt,
                'expires_at' => $this->createdAt + J6PreviewCache::TTL_SECONDS,
                'root_hashes' => $roots,
                'evidence_root_hashes' => $evidenceRoots,
                'counts' => $this->counts,
                'pages' => $this->chunks,
                'summary' => $summary,
            ];
            $this->write('metadata.json', $metadata);
            $receipt = new J6CalculationReceipt($header, [], $roots,
                array_intersect_key($this->counts, array_flip(['decision', 'result', 'coverage', 'usage'])), $summary, $this->token,
                [], $evidenceRoots, array_intersect_key($this->counts, array_flip(['funding_reference', 'funding_history'])));
            $target = $this->cache->previewPath($this->token, false);
            if (!rename($this->work, $target)) throw new \RuntimeException('J6 preview cannot be published.');
            $this->closed = true;
            try { $this->enforceRetention(); } catch (\Throwable) {}
            return $receipt;
        } catch (\Throwable $failure) {
            $this->abort();
            throw $failure;
        }
    }

    public function abort(): void
    {
        if ($this->closed) return;
        if (is_dir($this->work)) $this->cache->removeOwnedDirectory($this->work);
        $this->closed = true;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try { $this->abort(); } catch (\Throwable) {}
        }
    }

    private function append(string $kind, array $rows): void
    {
        $this->requireOpen();
        if ($rows === []) return;
        $evidence = $this->evidence($kind);
        $chunk = $evidence ? $this->evidenceHasher->page($kind, $this->chunks[$kind], $rows)
            : $this->hasher->page($kind, $this->chunks[$kind], $rows);
        $this->write(sprintf('%s-%08d.json', $kind, $this->chunks[$kind]), $chunk);
        if ($evidence) $this->evidenceHasher->updateRoot($this->roots[$kind], $chunk['descriptor']);
        else $this->hasher->updateRoot($this->roots[$kind], $chunk['descriptor']);
        $this->counts[$kind] += $chunk['descriptor']['row_count'];
        ++$this->chunks[$kind];
    }

    private function write(string $name, array $payload): void
    {
        if (preg_match('/^(?:\.j6-preview-owner|metadata\.json|(?:decision|result|coverage|usage|funding_reference|funding_history)-[0-9]{8}\.json)$/D', $name) !== 1) {
            throw new \InvalidArgumentException('J6 preview filename is invalid.');
        }
        $path = $this->work . DIRECTORY_SEPARATOR . $name;
        $part = $path . '.part';
        $this->cache->assertChild($this->work, $path, false);
        $this->cache->assertChild($this->work, $part, false);
        $bytes = NonprofitTaxRules::canonicalJson($payload);
        $handle = fopen($part, 'x+b');
        if (!is_resource($handle)) throw new \RuntimeException('J6 preview file cannot be opened.');
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) throw new \RuntimeException('J6 preview file write was incomplete.');
        } finally {
            fclose($handle);
        }
        if (!rename($part, $path)) throw new \RuntimeException('J6 preview file cannot be published.');
    }

    private function enforceRetention(): void
    {
        $matches = [];
        foreach (new \DirectoryIterator($this->cache->base()) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || preg_match('/^preview-[a-f0-9]{64}$/D', $entry->getFilename()) !== 1) continue;
            try {
                $meta = $this->cache->metadata($entry->getPathname());
                if (($meta['identity']['actor_id'] ?? null) === $this->identity->actorId
                    && ($meta['identity']['supplier_id'] ?? null) === $this->identity->supplierId
                    && ($meta['identity']['period_id'] ?? null) === $this->identity->periodId) {
                    $matches[] = ['path' => $entry->getPathname(), 'created_at' => (int) ($meta['created_at'] ?? 0),
                        'token' => (string) ($meta['token'] ?? '')];
                }
            } catch (\Throwable) {}
        }
        usort($matches, static fn (array $a, array $b): int => ($b['created_at'] <=> $a['created_at']) ?: strcmp($b['token'], $a['token']));
        foreach (array_slice($matches, J6PreviewCache::MAX_PER_SCOPE) as $stale) $this->cache->removeOwnedDirectory($stale['path']);
    }

    private function requireOpen(): J6CalculationHeader
    {
        if ($this->header === null || $this->closed) throw new \LogicException('J6 preview sink is not open.');
        return $this->header;
    }

    private function evidence(string $kind): bool
    {
        return in_array($kind, ['funding_reference', 'funding_history'], true);
    }
}
